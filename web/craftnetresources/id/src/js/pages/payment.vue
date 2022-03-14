<template>
    <div>
        <p>
            <template v-if="user">
                <router-link to="/cart">← Cart</router-link>
            </template>
            <template v-else>
                <router-link to="/identity">← Identity</router-link>
            </template>
        </p>
        <h1>Payment</h1>

        <div v-if="error">{{error}}</div>
        <spinner v-if="!cart"></spinner>

        <template v-else>
            <form @submit.prevent="pay()">
                <div class="md:flex -mx-8">
                    <div class="md:w-1/2 px-8">
                        <h2>Payment Method</h2>

                        <payment-method
                                ref="paymentMethod"
                                :card="card"
                                :cardToken="cardToken"
                                :paymentMode.sync="paymentMode"
                                :replaceCard.sync="replaceCard"></payment-method>

                        <h2 class="mt-4">Coupon Code</h2>
                        <coupon-code ref="couponCode"></coupon-code>
                    </div>
                    <div class="md:w-1/2 px-8 border-l">
                        <h2>Billing Info</h2>
                        <billing-info
                                :billingInfo.sync="billingInfo"
                                :errors="errors"></billing-info>
                    </div>
                </div>

                <div class="text-center mt-8">
                    <btn kind="primary" type="submit" :loading="payLoading" large :disabled="$refs.couponCode && $refs.couponCode.couponCodeLoading">Pay {{cart.totalPrice|currency}}</btn>

                    <div class="mt-4">
                        <img src="~@/images/powered_by_stripe.svg" height="18" />
                    </div>
                </div>
            </form>
        </template>
    </div>
</template>

<script>
    import {mapState, mapGetters, mapActions} from 'vuex'
    import PaymentMethod from '../components/payment/PaymentMethod'
    import CouponCode from '../components/payment/CouponCode'
    import BillingInfo from '../components/payment/BillingInfo'

    export default {
        data() {
            return {
                billingInfo: {
                    firstName: '',
                    lastName: '',
                    businessName: '',
                    businessTaxId: '',
                    address1: '',
                    address2: '',
                    country: '',
                    state: '',
                    city: '',
                    zipCode: '',
                },
                billingInfoErrors: {},
                paymentMode: 'newCard',
                replaceCard: false,
                loading: false,
                payLoading: false,
                error: null,
                errors: {},
                cardToken: null,
                guestCardToken: null,
            }
        },

        components: {
            PaymentMethod,
            CouponCode,
            BillingInfo,
        },

        computed: {
            ...mapState({
                cart: state => state.cart.cart,
                card: state => state.stripe.card,
                existingCardToken: state => state.stripe.cardToken,
                accountBillingAddress: state => state.account.billingAddress,
                user: state => state.account.user,
            }),

            ...mapGetters({
                cartTotal: 'cart/cartTotal',
            }),
        },

        methods: {
            ...mapActions({
                getCart: 'cart/getCart',
                getStripeAccount: 'stripe/getStripeAccount',
            }),

            pay() {
                this.payLoading = true

                this.savePaymentMethod()
                    .then(() => {
                        this.errors = {}
                        this.saveBillingInfos()
                            .then(() => {
                                this.processPayment()
                                    .then((response) => {
                                        if (response.data.redirect) {
                                          window.location.href=response.data.redirect;
                                          return;
                                        }

                                        this.$store.dispatch('app/displayNotice', 'Payment processed.')
                                        this.payLoading = false
                                        this.$router.push({path: '/thank-you'})
                                    })
                                    .catch(() => {
                                        this.$store.dispatch('app/displayError', 'Couldn’t process payment.')
                                        this.payLoading = false
                                    })
                            })
                            .catch((response) => {
                                let errors = {}

                                if (response.response.data.errors) {
                                    response.response.data.errors.forEach(error => {
                                        errors[error.param] = [error.message]
                                    })
                                }

                                this.errors = errors

                                this.$store.dispatch('app/displayError', 'Couldn’t save billing info.')
                                this.payLoading = false
                            })
                    })
                    .catch((error) => {
                        this.$store.dispatch('app/displayError', 'Couldn’t save payment method.')
                        this.payLoading = false
                        throw error
                    })
            },

            savePaymentMethod() {
                return new Promise((resolve, reject) => {
                    if (this.cart && this.cart.totalPrice > 0) {
                        if (this.user) {
                            // Save card for existing user
                            if (this.paymentMode === 'newCard') {
                                // Save new card
                                if (!this.cardToken) {
                                    this.$refs.paymentMethod.$refs.newCard.save(
                                        // success
                                        (source) => {
                                            this.cardToken = source
                                            resolve()
                                        },
                                        // error
                                        () => {
                                            reject()
                                        })
                                } else {
                                    resolve()
                                }
                            } else {
                                resolve()
                            }
                        } else {
                            // Save card for guest user
                            this.$refs.paymentMethod.$refs.guestCard.save(
                                // success
                                (response) => {
                                    this.guestCardToken = response
                                    resolve()
                                },
                                // error
                                () => {
                                    reject()
                                })
                        }
                    } else {
                        resolve()
                    }
                })
            },

            saveBillingInfos() {
                let cartData = {
                    billingAddress: {
                        firstName: this.billingInfo.firstName,
                        lastName: this.billingInfo.lastName,
                        businessName: this.billingInfo.businessName,
                        businessTaxId: this.billingInfo.businessTaxId,
                        address1: this.billingInfo.address1,
                        address2: this.billingInfo.address2,
                        country: this.billingInfo.country,
                        state: this.billingInfo.state,
                        city: this.billingInfo.city,
                        zipCode: this.billingInfo.zipCode,
                    },
                }

                if (this.user) {
                    cartData.email = this.user.email
                }

                return this.$store.dispatch('cart/saveCart', cartData)
            },

            processPayment() {
                let cardToken = null

                if (this.cart.totalPrice > 0) {
                    if (this.user) {
                        switch (this.paymentMode) {
                            case 'newCard':
                                cardToken = this.cardToken.id
                                break
                            default:
                                cardToken = this.existingCardToken
                        }
                    } else {
                        cardToken = this.guestCardToken.id
                    }
                }

                let checkoutData = {
                    orderNumber: this.cart.number,
                    token: cardToken,
                    expectedPrice: this.cart.totalPrice,
                    makePrimary: this.replaceCard,
                }

                return this.$store.dispatch('cart/checkout', checkoutData)
                    .then((response) => {
                        this.$store.dispatch('cart/resetCart')
                        return response;
                    })
            },
        },

        mounted() {
            this.loading = true

            this.getCart()
                .then(() => {
                    if (this.user) {
                        this.getStripeAccount()
                            .then(() => {
                                this.loading = false
                            })
                            .catch(() => {
                                this.loading = false
                            })
                    } else {
                        this.loading = false
                    }
                })
                .catch(() => {
                    this.loading = false
                })

            if (this.card) {
                this.paymentMode = 'existingCard'
            }

            this.$nextTick(() => {
                if(this.accountBillingAddress) {
                    this.billingInfo = JSON.parse(JSON.stringify(this.accountBillingAddress))
                }
            })
        }
    }
</script>
