import Vue from 'vue'
import Vuex from 'vuex'
import accountApi from '../../api/account'
import usersApi from '../../api/users';

Vue.use(Vuex)

/**
 * State
 */
const state = {
    billingAddress: null,
    user: null,
    accountLoading: false,
    userLoaded: false,
    craftSessionLoaded: false,
    hasApiToken: false,
}

/**
 * Getters
 */
const getters = {
    userIsInGroup(state) {
        return handle => {
            return state.user.groups.find(g => g.handle === handle)
        }
    },
}

/**
 * Actions
 */
const actions = {
    deleteUserPhoto({commit}) {
        return new Promise((resolve, reject) => {
            accountApi.deleteUserPhoto()
                .then((response) => {
                    commit('deleteUserPhoto', {response})
                    resolve(response)
                })
                .catch((response) => {
                    reject(response)
                })
        })
    },

    generateApiToken({commit}) {
        return new Promise((resolve, reject) => {
            accountApi.generateApiToken()
                .then((response) => {
                    if (response.data && !response.data.error) {
                        commit('updateHasApiToken', {hasApiToken: !!response.data.apiToken})
                        resolve(response)
                    } else {
                        reject(response)
                    }
                })
                .catch((error) => {
                    reject(error.response)
                })
        })
    },

    saveBillingInfo({commit}, data) {
        return new Promise((resolve, reject) => {
            accountApi.saveBillingInfo(data)
                .then((response) => {
                    if (!response.data.errors) {
                        commit('updateBillingAddress', {billingAddress: response.data.address})
                        resolve(response)
                    } else {
                        reject(response)
                    }
                })
                .catch((error) => {
                    reject(error.response)
                })
        })
    },

    saveUser({commit}, user) {
        return new Promise((resolve, reject) => {
            usersApi.saveUser(user)
                .then((response) => {
                    if (!response.data.errors) {
                        commit('saveUser', {user, response})
                        resolve(response)
                    } else {
                        reject(response)
                    }
                })
                .catch((error) => {
                    reject(error.response)
                })
        })
    },

    uploadUserPhoto({commit}, data) {
        return new Promise((resolve, reject) => {
            accountApi.uploadUserPhoto(data)
                .then((response) => {
                    commit('uploadUserPhoto', {response})
                    resolve(response)
                })
                .catch((response) => {
                    reject(response)
                })
        })
    },

    getAccount({commit}) {
        return new Promise((resolve, reject) => {
            accountApi.getAccount()
                .then((response) => {
                    commit('updateBillingAddress', {billingAddress: response.data.billingAddress})
                    commit('updateHasApiToken', {hasApiToken: response.data.user.hasApiToken})
                    commit('updateCurrentUser', {user: response.data.user})
                    commit('stripe/updateCard', {card: response.data.card}, {root: true})
                    commit('stripe/updateCardToken', {cardToken: response.data.cardToken}, {root: true})
                    commit('updateCurrentUserLoaded', true)

                    resolve(response)
                })
                .catch((response) => {
                    commit('updateCurrentUserLoaded', true)
                    reject(response)
                })
        })
    },

    loadAccount({commit, state, dispatch}) {
        return new Promise((resolve) => {
            if (!state.accountLoading) {
                commit('updateAccountLoading', true)

                let loadAccount = false

                if (!state.craftSessionLoaded) {
                    commit('updateCraftSessionLoaded', true)

                    if (window.loggedIn) {
                        loadAccount = true
                    } else {
                        commit('updateCurrentUserLoaded', true)
                    }
                } else {
                    if (!state.userLoaded) {
                        loadAccount = true
                    }
                }

                if (loadAccount) {
                    dispatch('getAccount')
                        .then(() => {
                            commit('updateAccountLoading', false)
                            resolve(true)
                        })
                        .catch(() => {
                            commit('updateAccountLoading', false)
                            resolve(false)
                        })
                } else {
                    commit('updateAccountLoading', false)
                    resolve(!!state.user)
                }
            } else {
                resolve(!!state.user)
            }
        })
    },

    getFlashMessages({commit}) {
        return new Promise((resolve, reject) => {
            accountApi.getFlashMessages()
                .then((response) => {
                    resolve(response)
                })
                .catch((response) => {
                    reject(response)
                })
        })
    },
}

/**
 * Mutations
 */
const mutations = {
    deleteUserPhoto(state, {response}) {
        state.user.photoId = response.data.photoId
        state.user.photoUrl = response.data.photoUrl
    },

    updateBillingAddress(state, {billingAddress}) {
        state.billingAddress = billingAddress
    },

    updateCraftSessionLoaded(state, loaded) {
        state.craftSessionLoaded = loaded
    },

    updateCurrentUser(state, {user}) {
        state.user = user
    },

    updateCurrentUserLoaded(state, loaded) {
        state.userLoaded = loaded
    },

    updateAccountLoading(state, loading) {
        state.accountLoading = loading
    },

    updateHasApiToken(state, {hasApiToken}){
        state.hasApiToken = hasApiToken
    },

    uploadUserPhoto(state, {response}) {
        state.user.photoId = response.data.photoId
        state.user.photoUrl = response.data.photoUrl
    },

    saveUser(state, {user}) {
        for (let attribute in user) {
            if (attribute === 'id' || attribute === 'email') {
                continue
            }

            state.user[attribute] = user[attribute]

            if (user.enablePluginDeveloperFeatures) {
                let groupExists = state.user.groups.find(g => g.handle === 'developers')

                if (!groupExists) {
                    state.user.groups.push({
                        id: 1,
                        name: 'Developers',
                        handle: 'developers',
                    })
                }
            }
        }
    },
}

export default {
    namespaced: true,
    state,
    getters,
    actions,
    mutations
}
