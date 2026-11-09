<template>
  <div class="modal-card video-preview">
    <header class="modal-card-head">
      <p class="modal-card-title">
        {{ item.name }}
      </p>
    </header>
    <section class="modal-card-body preview-body">
      <video :src="source" class="preview-video" controls autoplay playsinline preload="metadata">
        {{ lang('Your browser does not support video playback') }}
      </video>
    </section>
    <footer class="modal-card-foot">
      <a class="button" :href="source" target="_blank" rel="noopener">{{ lang('Download') }}</a>
      <button class="button" type="button" @click="$parent.close()">
        {{ lang('Close') }}
      </button>
    </footer>
  </div>
</template>

<script>
export default {
  name: 'VideoPreview',
  props: {
    item: {
      type: Object,
      required: true,
    },
  },
  computed: {
    source() {
      return this.getDownloadLink(this.item.path)
    },
  },
}
</script>

<style scoped>
.video-preview {
  width: 90vw;
  max-width: 1000px;
}
.preview-body {
  min-height: 420px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: #111;
}
.preview-video {
  max-width: 100%;
  max-height: 70vh;
}
</style>
