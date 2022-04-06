<template>
    <div>
        <template v-if="loading">
            <spinner></spinner>
        </template>
        <template else>
            <template v-if="license">
                <p><router-link class="nav-link" to="/licenses/cms" exact>← Craft CMS</router-link></p>
                <h1><code>{{ license.key.substr(0, 10) }}</code></h1>

                <cms-license-details :license.sync="license"></cms-license-details>

                <div class="card mb-3">
                    <div class="card-body">
                        <h4>Plugin Licenses</h4>

                        <template v-if="license.pluginLicenses.length > 0">
                            <p class="text-secondary mb-4">Plugin licenses attached to this Craft CMS license.</p>
                            <plugin-licenses-table :licenses="license.pluginLicenses" :exclude-cms-license-column="true" :exclude-notes-column="true" :auto-renew-switch="true"></plugin-licenses-table>

                            <template v-if="hasPluginRenewals && !(license.expirable && license.expiresOn)">
                                <btn @click="showRenewLicensesModal('cms')">Renew your plugin licenses…</btn>
                            </template>
                        </template>
                        <template v-else>
                            <p class="text-secondary mb-4">No plugin licenses are attached to this Craft CMS license.</p>
                        </template>
                    </div>
                </div>

                <license-history :history="license.history" />

                <div class="card card-danger mb-3">
                    <div class="card-header">Danger Zone</div>
                    <div class="card-body">
                        <h5>Release license</h5>
                        <p>Release this license if you no longer wish to use it, so that it can be claimed by someone else.</p>
                        <div>
                            <btn kind="danger" @click="releaseCmsLicense()">Release License</btn>
                        </div>
                    </div>
                </div>
            </template>
        </template>
    </div>
</template>

<script>
    import {mapActions} from 'vuex'
    import cmsLicensesApi from '../../../api/cms-licenses'
    import CmsLicenseDetails from '../../../components/licenses/CmsLicenseDetails'
    import PluginLicensesTable from '../../../components/licenses/PluginLicensesTable'
    import LicenseHistory from '../../../components/licenses/LicenseHistory'

    export default {
        components: {
            CmsLicenseDetails,
            PluginLicensesTable,
            LicenseHistory,
        },

        data() {
            return {
                loading: false,
                license: null,
            }
        },

        computed: {
            hasPluginRenewals() {
              return Object.keys(this.license.pluginRenewalOptions).length > 0
            }
        },

        methods: {
            ...mapActions({
                showRenewLicensesModal: 'app/showRenewLicensesModal',
            }),

            releaseCmsLicense() {
                if (!window.confirm("Are you sure you want to release this license?")) {
                    return false
                }

                cmsLicensesApi.releaseCmsLicense(this.license.key)
                    .then((response) => {
                        if (response.data && !response.data.error) {
                            this.$store.dispatch('app/displayNotice', 'CMS license released.')
                            this.$router.push({path: '/licenses/cms'})
                        } else {
                            this.$store.dispatch('app/displayError', response.data.error)
                        }
                    })
                    .catch((error) => {
                        const errorMessage = error.response.data && error.response.data.error ? error.response.data.error : 'Couldn’t release CMS license.'
                        this.$store.dispatch('app/displayError', errorMessage)
                    })
            },
        },

        mounted() {
            const licenseId = this.$route.params.id

            this.loading = true

            cmsLicensesApi.getCmsLicense(licenseId)
                .then((response) => {
                    this.license = response.data
                    this.$store.commit('app/updateRenewLicense', this.license)
                    this.loading = false
                })
                .catch(() => {
                    this.loading = false
                })
        }
    }
</script>
