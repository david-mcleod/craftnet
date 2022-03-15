<template>
    <div>
        <h1>Cart</h1>

        <spinner v-if="loading"></spinner>

        <template v-else>
            <template v-if="cart">
                <template v-if="cartItems.length">
                    <table class="table cart-data">
                        <template v-if="cartItems.length">
                            <thead>
                            <tr>
                                <th colspan="2">Item</th>
                                <th>Updates</th>
                                <th class="hidden">Quantity</th>
                                <th></th>
                            </tr>
                            </thead>

                            <tbody v-for="(item, itemKey) in cartItems" :key="itemKey">
                                <tr>
                                    <template v-if="item.lineItem.purchasable.type === 'cms-edition'">
                                        <td class="icon-col">
                                            <div class="plugin-icon">
                                                <img :src="staticImageUrl('craft.svg')" width="42" height="42" />
                                            </div>
                                        </td>
                                        <td class="description">
                                            <strong>Craft CMS</strong>
                                            <edition-badge>{{ item.lineItem.purchasable.name }}</edition-badge>
                                        </td>
                                    </template>

                                    <template v-else-if="item.lineItem.purchasable.type === 'plugin-edition'">
                                        <td class="icon-col">
                                            <div v-if="item.plugin" class="plugin-icon">
                                                <img v-if="item.plugin.iconUrl" :src="item.plugin.iconUrl" width="42" height="42" />
                                            </div>
                                        </td>
                                        <td class="description">
                                            <strong v-if="item.plugin">{{item.plugin.name}}</strong>
                                            <edition-badge>{{ item.lineItem.purchasable.name }}</edition-badge>
                                        </td>
                                    </template>

                                    <template v-else>
                                        <td colspan="2" class="description">
                                            <strong>
                                                <template v-if="item.lineItem.purchasable.type === 'cms-renewal'">
                                                    Craft CMS
                                                    {{item.lineItem.description}}
                                                </template>
                                                <template v-else>
                                                    {{item.lineItem.description}}
                                                </template>
                                            </strong>

                                            <div class="text-secondary">
                                                <code>{{ item.lineItem.options.licenseKey.substr(0, 4) }}</code>
                                            </div>
                                        </td>
                                    </template>

                                    <td class="expiry-date">
                                        <div class="expiry-date-flex">
                                            <div>
                                                <template v-if="item.lineItem.purchasable.type === 'cms-edition' || item.lineItem.purchasable.type === 'plugin-edition'">
                                                    <dropdown v-model="selectedExpiryDates[item.id]" :options="itemUpdateOptions(itemKey)" @input="onSelectedExpiryDateChange(itemKey)" />
                                                </template>
                                                <template v-else>
                                                    <span>Updates until <strong>{{item.lineItem.options.expiryDate}}</strong></span>
                                                </template>
                                            </div>

                                            <spinner v-if="itemLoading(itemKey)"></spinner>
                                        </div>
                                    </td>
                                    <td class="hidden">
                                        <textbox
                                                ref="quantityInput"
                                                v-model="itemQuantity[itemKey]"
                                                min="minQuantity"
                                                max="maxQuantity"
                                                step="1"
                                                @keydown="onQuantityKeyDown($event, itemKey)"
                                                @input="onQuantityInput($event, itemKey)"
                                                :disabled="1 === 1 || (item.lineItem.purchasable.type === 'cms-edition' || item.lineItem.purchasable.type === 'plugin-edition' ? false : true)"
                                        />
                                    </td>
                                    <td class="text-right">
                                        <strong class="block text-xl">
                                            {{ item.lineItem.subtotal|currency }}
                                        </strong>
                                        <a @click="removeFromCart(itemKey)">Remove</a>
                                    </td>
                                </tr>

                                <template v-for="(adjustment, adjustmentKey) in item.lineItem.adjustments">
                                    <tr :key="itemKey + 'adjustment-' + adjustmentKey" class="sub-item">
                                        <td class="blank-cell"></td>
                                        <td colspan="2">
                                            {{adjustment.name}}
                                        </td>
                                        <td class="price text-right">
                                            {{adjustment.amount|currency}}
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                            <tbody>
                            <tr>
                                <th class="text-right text-xl" colspan="3">Total</th>
                                <td class="text-right text-xl"><strong>{{ cart.totalPrice|currency }}</strong></td>
                            </tr>
                            </tbody>
                        </template>
                    </table>

                    <div class="mt-4 text-right">
                        <btn kind="primary" large @click="checkout()">Check Out</btn>
                    </div>
                </template>

                <div v-else>
                    <empty>
                        <icon icon="shopping-cart" class="size-4xl mb-4 text-grey" />
                        <div class="font-bold">Your cart is empty</div>
                        <div class="mt-4">
                            <p>Browse plugins on the <a :href="craftPluginsUrl()">Plugin Store</a>.</p>
                        </div>
                    </empty>
                </div>
            </template>
        </template>
    </div>
</template>

<script>
    import {mapState, mapGetters, mapActions} from 'vuex'
    import Empty from '../components/Empty'
    import EditionBadge from '../components/EditionBadge'
    import helpers from '../mixins/helpers'

    export default {
        mixins: [helpers],

        components: {
            Empty,
            EditionBadge,
        },

        data() {
            return {
                loading: false,
                loadingItems: {},
                itemQuantity: {},
                minQuantity: 1,
                maxQuantity: 1000,
            }
        },

        computed: {
            ...mapState({
                cart: state => state.cart.cart,
                expiryDateOptions: state => state.pluginStore.expiryDateOptions,
                user: state => state.account.user,
            }),

            ...mapGetters({
                cartItems: 'cart/cartItems',
                cartItemsData: 'cart/cartItemsData',
            }),

            selectedExpiryDates: {
                get() {
                    return JSON.parse(JSON.stringify(this.$store.state.cart.selectedExpiryDates))
                },
                set(newValue) {
                    this.$store.commit('cart/updateSelectedExpiryDates', newValue)
                }
            },
        },

        methods: {
            ...mapActions({
                getCart: 'cart/getCart',
                removeFromCart: 'cart/removeFromCart',
                getCoreData: 'pluginStore/getCoreData',
            }),

            checkout() {
                if (!this.user) {
                    this.$router.push({path: '/identity'})
                    return
                }

                this.$router.push({path: '/payment'})
            },

            itemUpdateOptions(itemKey) {
                const item = this.cartItems[itemKey]
                const renewalPrice = parseFloat(item.lineItem.purchasable.renewalPrice)

                let options = []
                let selectedOption = 0

                this.expiryDateOptions.forEach((option, key) => {
                    if (option[0] === item.lineItem.options.expiryDate) {
                        selectedOption = key
                    }
                })

                for (let i = 0; i < this.expiryDateOptions.length; i++) {
                    const expiryDateOption = this.expiryDateOptions[i]
                    const value = expiryDateOption[0]
                    const date = expiryDateOption[1]
                    const price = renewalPrice * (i - selectedOption)

                    let label = "Updates Until " + this.$options.filters.moment(date, 'YYYY-MM-DD')

                    if (price !== 0) {
                        let sign = ''

                        if (price > 0) {
                            sign = '+'
                        }

                        label += " (" + sign + this.$options.filters.currency(price) + ")"
                    }

                    options.push({
                        label: label,
                        value: value,
                    })
                }

                return options
            },

            onQuantityInput(value, itemKey) {
                value = parseInt(value)

                if (isNaN(value) || value < this.minQuantity) {
                    value = this.minQuantity
                } else if (value > this.maxQuantity) {
                    value = this.maxQuantity
                }

                this.$set(this.itemQuantity, itemKey, value)
                this.$refs.quantityInput[itemKey].$el.value = value
            },

            onQuantityKeyDown($event) {
                let charCode = ($event.which) ? $event.which : $event.keyCode

                // prevent `e` and `-` to prevent exponent and negative notations
                if (charCode === 69 || charCode === 189) {
                    $event.preventDefault()

                    return false
                }
            },

            onSelectedExpiryDateChange(itemKey) {
                this.$set(this.loadingItems, itemKey, true)
                let item = this.cartItemsData[itemKey]
                item.expiryDate = this.selectedExpiryDates[item.id]
                this.$store.dispatch('cart/updateItem', {itemKey, item})
                    .then(() => {
                        this.$delete(this.loadingItems, itemKey)
                    })
            },

            itemLoading(itemKey) {
                if (!this.loadingItems[itemKey]) {
                    return false
                }

                return true
            },

            initializeCart() {
              this.loading = true

              this.getCoreData()
                .then(() => {
                  this.getCart()
                    .then(() => {
                      this.loading = false

                      this.cartItems.forEach(function(item, key) {
                        this.$set(this.itemQuantity, key, 1)
                      }.bind(this))

                    })
                    .catch(() => {
                      this.loading = false
                    })
                })
                .catch(() => {
                  this.loading = false
                })
            }
        },

        mounted() {
          // Replace the existing order number with the one provided in the query params (if any)
          if (this.$route.query.orderNumber) {
            const orderNumber = this.$route.query.orderNumber
            this.$store.commit('cart/resetCart')
            this.$store.dispatch('cart/saveOrderNumber', {orderNumber})
            this.$router.push('/cart', () => {
              this.initializeCart()
            })
          } else {
            this.initializeCart()
          }

        },
    }
</script>

<style lang="scss">
    .cart-data {
        td.description {
            strong {
                @apply .mr-2 .text-xl;
            }

            .edition-badge {
                @apply .relative;
                top: -2px;
            }
        }

        .expiry-date {
            @apply .w-2/5;

            .expiry-date-flex {
                @apply .flex .flex-row .items-center;

                .c-field {
                    @apply .mb-0;
                }
            }
        }

        .c-spinner {
            @apply .ml-4;
        }
    }
</style>
