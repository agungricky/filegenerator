<template>
  <div class="modal-card share-links">
    <header class="modal-card-head">
      <p class="modal-card-title">{{ lang('Shared links') }}</p>
    </header>
    <section class="modal-card-body">
      <p v-if="!loading && !shares.length" class="has-text-grey">{{ lang('No shared links yet') }}</p>
      <div v-for="share in shares" :key="share.token" class="share-row">
        <div>
          <strong>{{ share.name }}</strong>
          <small class="is-block has-text-grey">{{ share.path }}</small>
          <a :href="getShareLink(share.token)" target="_blank" rel="noopener">{{ getShareLink(share.token) }}</a>
        </div>
        <div class="share-actions">
          <button class="button is-small" type="button" @click="copy(share)">
            <b-icon icon="clipboard" size="is-small" />
          </button>
          <button class="button is-small is-danger is-light" type="button" @click="revoke(share)">
            <b-icon icon="trash-alt" size="is-small" />
          </button>
        </div>
      </div>
      <b-loading :is-full-page="false" :active.sync="loading" />
    </section>
    <footer class="modal-card-foot">
      <button class="button" type="button" @click="$parent.close()">{{ lang('Close') }}</button>
    </footer>
  </div>
</template>

<script>
import api from '../../api/api'

export default {
  name: 'ShareLinks',
  data() {
    return {
      shares: [],
      loading: false,
    }
  },
  mounted() {
    this.load()
  },
  methods: {
    load() {
      this.loading = true
      api.listShares()
        .then(shares => { this.shares = shares })
        .catch(error => this.handleError(error))
        .then(() => { this.loading = false })
    },
    copy(share) {
      this.$copyText(this.getShareLink(share.token))
        .then(() => this.$toast.open({ message: this.lang('Link copied'), type: 'is-success' }))
        .catch(() => this.$toast.open({ message: this.lang('Unable to copy link'), type: 'is-danger' }))
    },
    revoke(share) {
      this.$dialog.confirm({
        message: this.lang('Revoke this shared link?'),
        type: 'is-danger',
        cancelText: this.lang('Cancel'),
        confirmText: this.lang('Revoke'),
        onConfirm: () => {
          this.loading = true
          api.revokeShare({ token: share.token })
            .then(() => { this.shares = this.shares.filter(item => item.token !== share.token) })
            .catch(error => this.handleError(error))
            .then(() => { this.loading = false })
        },
      })
    },
  },
}
</script>

<style scoped>
.share-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 1rem;
  padding: .75rem 0;
  border-bottom: 1px solid #eee;
  word-break: break-word;
}
.share-actions {
  display: flex;
  gap: .35rem;
  flex-shrink: 0;
}
</style>
