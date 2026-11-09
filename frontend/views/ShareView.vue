<template>
  <div class="container share-view">
    <header class="share-header">
      <img :src="$store.state.config.logo" :alt="$store.state.config.app_name">
      <span>{{ lang('Public shared folder') }}</span>
    </header>
    <div class="share-content">
      <div class="share-heading">
        <div>
          <h1 class="title is-4">{{ share.name || lang('Shared folder') }}</h1>
          <div class="breadcrumb" aria-label="breadcrumbs">
            <ul>
              <li v-for="(item, index) in breadcrumbs" :key="index">
                <a @click="goTo(item.path)">{{ item.name }}</a>
              </li>
            </ul>
          </div>
        </div>
        <span class="tag is-light">{{ lang('Read-only') }}</span>
      </div>

      <b-notification v-if="error" type="is-danger" :closable="false">{{ error }}</b-notification>
      <b-table v-if="!error" :data="files" :loading="isLoading" :hoverable="true" :row-class="row => 'file-row type-' + row.type">
        <template slot-scope="props">
          <b-table-column :label="lang('Name')">
            <a class="is-block name" @click="itemClick(props.row)">{{ props.row.name }}</a>
          </b-table-column>
          <b-table-column :label="lang('Size')" numeric width="150">
            {{ props.row.type == 'back' || props.row.type == 'dir' ? lang('Folder') : formatBytes(props.row.size) }}
          </b-table-column>
          <b-table-column :label="lang('Time')" numeric width="200">
            {{ props.row.time ? formatDate(props.row.time) : '' }}
          </b-table-column>
          <b-table-column width="100">
            <button v-if="props.row.type == 'file' && hasMediaPreview(props.row.name)" class="button is-small mr-1" type="button" @click="preview(props.row)">
              <b-icon icon="eye" size="is-small" />
            </button>
            <button v-if="props.row.type == 'file'" class="button is-small" type="button" @click="download(props.row)">
              <b-icon icon="download" size="is-small" />
            </button>
          </b-table-column>
        </template>
      </b-table>
    </div>
  </div>
</template>

<script>
import SharePreview from './partials/SharePreview'
import api from '../api/api'
import _ from 'lodash'

export default {
  name: 'ShareView',
  components: {},
  data() {
    return {
      token: this.$route.query.token || '',
      share: {},
      files: [],
      location: '/',
      isLoading: false,
      error: '',
    }
  },
  computed: {
    breadcrumbs() {
      const crumbs = [{ name: this.share.name || this.lang('Shared folder'), path: '/' }]
      let path = ''
      _.forEach(_.split(this.location, '/'), dir => {
        if (!dir) return
        path += '/' + dir
        crumbs.push({ name: dir, path: path })
      })
      return crumbs
    },
  },
  watch: {
    '$route'() {
      this.token = this.$route.query.token || ''
      this.load()
    },
  },
  mounted() {
    this.load()
  },
  methods: {
    load() {
      if (!this.token) {
        this.error = this.lang('Invalid share link')
        return
      }
      this.isLoading = true
      this.error = ''
      api.getShared({ token: this.token, path: this.$route.query.path || '/' })
        .then(data => {
          this.share = data.share
          this.files = data.files
          this.location = data.location
        })
        .catch(error => {
          this.error = error.response && error.response.data && error.response.data.data
            ? this.lang(error.response.data.data)
            : this.lang('Share not found')
        })
        .then(() => { this.isLoading = false })
    },
    goTo(path) {
      const query = { token: this.token }
      if (path !== '/') query.path = path
      this.$router.push({ name: 'share', query: query }).catch(() => {})
    },
    itemClick(item) {
      if (item.type === 'dir' || item.type === 'back') {
        this.goTo(item.path)
      } else if (this.hasMediaPreview(item.name)) {
        this.preview(item)
      } else {
        this.download(item)
      }
    },
    hasMediaPreview(name) {
      return this.isImage(name) || this.isVideo(name)
    },
    preview(item) {
      this.$modal.open({
        parent: this,
        hasModalCard: true,
        component: SharePreview,
        props: { item: item, token: this.token },
      })
    },
    download(item) {
      window.open(this.getSharedDownloadLink(this.token, item.path), '_blank')
    },
  },
}
</script>

<style scoped>
.share-view {
  margin: 0 auto 100px auto;
}
.share-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 1rem;
  padding: 1rem 0;
  border-bottom: 1px solid #eee;
  color: #777;
}
.share-header img {
  max-height: 2.5rem;
  max-width: 220px;
}
.share-content {
  margin: 50px auto 0 auto;
}
.share-heading {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}
.file-row a.name {
  color: #373737;
  word-break: break-all;
}
.file-row.type-dir a.name {
  font-weight: bold;
}
</style>
