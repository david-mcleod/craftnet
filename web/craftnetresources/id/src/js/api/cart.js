/* global Craft */

import axios from 'axios'

export default {
    /**
     * Axios instance.
     */
    _axios: null,

    /**
     * Returns the axios instance for calls to the Craft API.
     */
    axios() {
        if(!this._axios) {
            this._axios = axios.create({
                headers: {
                    'X-CSRF-Token':  Craft.csrfTokenValue
                },
                baseURL: process.env.VUE_APP_CRAFT_ID_ENDPOINT + '/',
                // params: {XDEBUG_SESSION_START: 16433}
            })
        }

        return this._axios
    },

    /**
     * Get cart.
     */
    getCart(orderNumber) {
        return this.axios().get('carts/' + orderNumber)
    },

    /**
     * Create cart.
     */
    createCart(data) {
        return this.axios().post('carts', data)
    },

    /**
     * Update cart.
     */
    updateCart(orderNumber, data) {
        return this.axios().post('carts/' + orderNumber, data)
    },

    /**
     * Checkout.
     */
    checkout(data) {
        return this.axios().post('payments', data, {
            withCredentials: true,
        })
    },

    /**
     * Reset order number.
     */
    resetOrderNumber() {
        localStorage.removeItem('orderNumber')
    },

    /**
     * Save order number
     */
    saveOrderNumber(orderNumber) {
        localStorage.setItem('orderNumber', orderNumber)
    },

    /**
     * Get order number.
     */
    getOrderNumber(cb) {
        const orderNumber = localStorage.getItem('orderNumber')

        return cb(orderNumber)
    },
}
