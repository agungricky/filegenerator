<?php

namespace Filegator\Controllers;

use Filegator\Config\Config;
use Filegator\Kernel\Request;
use Filegator\Kernel\Response;
use Filegator\Kernel\StreamedResponse;
use Filegator\Services\Auth\AuthInterface;
use Filegator\Services\Storage\Filesystem;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\Mime\MimeTypes;

/**
 * Creates and serves read-only links to folders.
 *
 * Share records are deliberately kept outside the repository so that a share
 * token is never exposed through the normal file browser.
 */
class ShareController
{
    protected $config;

    protected $auth;

    protected $storage;

    public function __construct(Config $config, AuthInterface $auth, Filesystem $storage)
    {
        $this->config = $config;
        $this->auth = $auth;
        $this->storage = $storage;
    }

    public function create(Request $request, Response $response)
    {
        $user = $this->auth->user();
        try {
            $path = $this->normalizePath($request->input('path', '/'));
        } catch (\InvalidArgumentException $e) {
            return $response->json('Invalid path', 422);
        }

        $this->storage->setPathPrefix($user->getHomeDir());

        if (! $this->storage->fileExists($path) || ! $this->storage->isDir($path)) {
            return $response->json('Folder not found', 404);
        }

        $token = bin2hex(random_bytes(32));
        $shares = $this->loadShares();
        $shares[] = [
            'token' => $token,
            'owner' => $user->getUsername(),
            'homedir' => $user->getHomeDir(),
            'path' => $path,
            'name' => $path === '/' ? '/' : basename($path),
            'created_at' => time(),
            'revoked_at' => null,
        ];
        $this->saveShares($shares);

        return $response->json($this->publicShare($shares[count($shares) - 1]));
    }

    public function list(Request $request, Response $response)
    {
        $user = $this->auth->user();
        $isAdmin = $user->isAdmin();
        $shares = array_values(array_filter($this->loadShares(), function ($share) use ($user, $isAdmin) {
            return empty($share['revoked_at']) && ($isAdmin || ($share['owner'] ?? '') === $user->getUsername());
        }));

        usort($shares, function ($a, $b) {
            return ($b['created_at'] ?? 0) <=> ($a['created_at'] ?? 0);
        });

        return $response->json(array_map([$this, 'publicShare'], $shares));
    }

    public function revoke(Request $request, Response $response, $token)
    {
        $user = $this->auth->user();
        $shares = $this->loadShares();
        $found = false;

        foreach ($shares as &$share) {
            if (! $this->sameToken($share, $token)) {
                continue;
            }
            if (! $user->isAdmin() && ($share['owner'] ?? '') !== $user->getUsername()) {
                return $response->json('Not allowed', 403);
            }
            if (empty($share['revoked_at'])) {
                $share['revoked_at'] = time();
                $found = true;
            }
        }
        unset($share);

        if (! $found) {
            return $response->json('Share not found', 404);
        }

        $this->saveShares($shares);

        return $response->json('Done');
    }

    public function show(Request $request, Response $response, $token)
    {
        $share = $this->findActiveShare($token);
        if (! $share) {
            return $response->json('Share not found', 404);
        }

        try {
            $relativePath = $this->normalizePath($request->input('path', '/'));
            $fullPath = $this->resolvePath($share['path'], $relativePath);
            $this->storage->setPathPrefix($share['homedir']);

            if (! $this->isWithin($fullPath, $share['path'])
                || ! $this->storage->fileExists($fullPath)
                || ! $this->storage->isDir($fullPath)
            ) {
                return $response->json('Folder not found', 404);
            }

            $collection = $this->storage->getDirectoryCollection($fullPath);
            $files = [];
            foreach ($collection->all() as $item) {
                if ($item['type'] === 'back' && $relativePath === '/') {
                    continue;
                }
                $item['path'] = $item['type'] === 'back'
                    ? $this->parentPath($relativePath)
                    : $this->relativePath($share['path'], $item['path']);
                $item['permissions'] = -1;
                $files[] = $item;
            }

            return $response->json([
                'share' => [
                    'name' => $share['name'],
                    'created_at' => $share['created_at'],
                ],
                'location' => $relativePath,
                'files' => $files,
            ]);
        } catch (\InvalidArgumentException $e) {
            return $response->json('Invalid path', 422);
        } catch (\Exception $e) {
            return $response->json('Folder not found', 404);
        }
    }

    public function download(Request $request, Response $response, StreamedResponse $streamedResponse, $token)
    {
        $share = $this->findActiveShare($token);
        if (! $share) {
            return $response->json('Share not found', 404);
        }

        try {
            $relativePath = $this->normalizePath($request->input('path', '/'));
            $fullPath = $this->resolvePath($share['path'], $relativePath);
            $this->storage->setPathPrefix($share['homedir']);

            if ($relativePath === '/'
                || ! $this->isWithin($fullPath, $share['path'])
                || ! $this->storage->fileExists($fullPath)
                || $this->storage->isDir($fullPath)
            ) {
                return $response->json('File not found', 404);
            }

            $file = $this->storage->readStream($fullPath);
        } catch (\InvalidArgumentException $e) {
            return $response->json('Invalid path', 422);
        } catch (\Exception $e) {
            return $response->json('File not found', 404);
        }

        $streamedResponse->setCallback(function () use ($file) {
            set_time_limit(0);
            if ($file['stream']) {
                while (! feof($file['stream'])) {
                    echo fread($file['stream'], 1024 * 8);
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
                fclose($file['stream']);
            }
        });

        $extension = strtolower(pathinfo($file['filename'], PATHINFO_EXTENSION));
        $mimes = (new MimeTypes())->getMimeTypes($extension);
        $contentType = ! empty($mimes) ? $mimes[0] : 'application/octet-stream';
        $disposition = HeaderUtils::DISPOSITION_ATTACHMENT;
        $downloadInline = (array) $this->config->get('download_inline', ['pdf']);
        $mediaExtensions = [
            'jpg', 'jpeg', 'gif', 'png', 'bmp', 'svg', 'tiff', 'tif', 'webp',
            'mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v',
        ];
        if (in_array($extension, $downloadInline)
            || in_array('*', $downloadInline)
            || in_array($extension, $mediaExtensions)
        ) {
            $disposition = HeaderUtils::DISPOSITION_INLINE;
        }

        $streamedResponse->headers->set(
            'Content-Disposition',
            HeaderUtils::makeDisposition($disposition, $file['filename'], 'file')
        );
        $streamedResponse->headers->set('Content-Type', $contentType);
        $streamedResponse->headers->set('Content-Transfer-Encoding', 'binary');
        $streamedResponse->headers->set('X-Content-Type-Options', 'nosniff');
        if (isset($file['filesize'])) {
            $streamedResponse->headers->set('Content-Length', $file['filesize']);
        }
        $streamedResponse->send();
    }

    protected function publicShare(array $share)
    {
        return [
            'token' => $share['token'],
            'name' => $share['name'],
            'path' => $share['path'],
            'created_at' => $share['created_at'],
            'owner' => $share['owner'],
        ];
    }

    protected function findActiveShare($token)
    {
        foreach ($this->loadShares() as $share) {
            if ($this->sameToken($share, $token) && empty($share['revoked_at'])) {
                return $share;
            }
        }

        return null;
    }

    protected function sameToken(array $share, $token)
    {
        return is_string($token)
            && preg_match('/^[a-f0-9]{64}$/', $token)
            && isset($share['token'])
            && hash_equals($share['token'], $token);
    }

    protected function normalizePath($path)
    {
        $path = str_replace('\\', '/', (string) $path);
        $segments = explode('/', trim($path, '/'));
        $clean = [];
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new \InvalidArgumentException('Parent paths are not allowed');
            }
            $clean[] = $segment;
        }

        return '/' . implode('/', $clean);
    }

    protected function resolvePath($root, $relative)
    {
        $root = $this->normalizePath($root);
        $relative = $this->normalizePath($relative);

        return $root === '/' ? $relative : ($relative === '/' ? $root : $root . $relative);
    }

    protected function isWithin($path, $root)
    {
        $path = $this->normalizePath($path);
        $root = $this->normalizePath($root);

        return $root === '/' || $path === $root || strpos($path, $root . '/') === 0;
    }

    protected function relativePath($root, $path)
    {
        $root = $this->normalizePath($root);
        $path = $this->normalizePath($path);

        if ($root === '/') {
            return $path;
        }

        $relative = substr($path, strlen($root));

        return $relative === '' ? '/' : '/' . ltrim($relative, '/');
    }

    protected function parentPath($path)
    {
        $path = trim($this->normalizePath($path), '/');
        if ($path === '') {
            return '/';
        }
        $parts = explode('/', $path);
        array_pop($parts);

        return empty($parts) ? '/' : '/' . implode('/', $parts);
    }

    protected function loadShares()
    {
        $file = $this->shareFile();
        if (! file_exists($file)) {
            return [];
        }

        $shares = json_decode((string) file_get_contents($file), true);

        return is_array($shares) ? $shares : [];
    }

    protected function saveShares(array $shares)
    {
        $file = $this->shareFile();
        $directory = dirname($file);
        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        file_put_contents($file, json_encode(array_values($shares), JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    protected function shareFile()
    {
        return $this->config->get('sharing.file', dirname(__DIR__, 2) . '/private/shares.json');
    }
}
