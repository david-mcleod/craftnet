/* global Craft */

import axios from 'axios'
import qs from 'qs'
import FormDataHelper from '../helpers/form-data'

export default {
    saveBillingInfo(data) {
        return axios.post(Craft.actionUrl + '/craftnet/id/account/save-billing-info', qs.stringify(data), {
                headers: {
                    'X-CSRF-Token':  Craft.csrfTokenValue,
                }
            })
    },

    uploadUserPhoto(data) {
        let formData = new FormData()

        for (let attribute in data) {
            FormDataHelper.append(formData, attribute, data[attribute])
        }

        return axios.post(Craft.actionUrl + '/craftnet/id/account/upload-user-photo', formData, {
                headers: {
                    'X-CSRF-Token':  Craft.csrfTokenValue,
                }
            })
    },

    deleteUserPhoto() {
        return axios.post(Craft.actionUrl + '/craftnet/id/account/delete-user-photo', {}, {
                headers: {
                    'X-CSRF-Token': Craft.csrfTokenValue,
                }
            })
    },

    generateApiToken() {
        return axios.post(Craft.actionUrl + '/craftnet/id/account/generate-api-token', {}, {
                headers: {
                    'X-CSRF-Token': Craft.csrfTokenValue,
                }
            })
    },

    getAccount() {
        return axios.post(Craft.actionUrl + '/craftnet/id/account/get-account', {}, {
            headers: {
                'X-CSRF-Token': Craft.csrfTokenValue,
            }
        })
    },

    getFlashMessages() {
        return axios.post(Craft.actionUrl + '/craftnet/id/account/get-flash-messages', {}, {
            headers: {
                'X-CSRF-Token': Craft.csrfTokenValue,
            }
        });
    }
}
