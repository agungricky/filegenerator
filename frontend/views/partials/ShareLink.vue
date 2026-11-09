<template>
  <div class="modal-card">
    <header class="modal-card-head">
      <p class="modal-card-title">{{ lang('Folder shared') }}</p>
    </header>
    <section class="modal-card-body">
      <p class="mb-4">{{ lang('Anyone with this link can browse and download this folder.') }}</p>
      <b-field :label="lang('Share link')">
        <b-input :value="link" readonly />
      </b-field>
    </section>
    <footer class="modal-card-foot">
      <button class="button" type="button" @click="$parent.close()">{{ lang('Close') }}</button>
      <button class="button is-primary" type="button" @click="copy">{{ lang('Copy link') }}</button>
    </footer>
  </div>
</template>

<script>
export default {
  name: 'ShareLink',
  props: {
    share: {
      type: Object,
      required: true,
    },
  },
  computed: {
    link() {
      return this.getShareLink(this.share.token)
    },
  },
  methods: {
    copy() {
      this.$copyText(this.link)
        .then(() => this.$toast.open({ message: this.lang('Link copied'), type: 'is-success' }))
        .catch(() => this.$toast.open({ message: this.lang('Unable to copy link'), type: 'is-danger' }))
    },
  },
}
</script>
