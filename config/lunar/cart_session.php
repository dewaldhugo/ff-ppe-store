<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Session Key
    |--------------------------------------------------------------------------
    |
    | Specify the session key used when fetching the cart.
    |
    */
    'session_key' => 'lunar_cart',

    /*
    |--------------------------------------------------------------------------
    | Auto create a cart when none exists for user.
    |--------------------------------------------------------------------------
    |
    | Determines whether you want to automatically create a cart for a user if
    | they do not currently have one in the session. By default, this is false
    | to minimise the amount of carts added to the database.
    |
    */
    'auto_create' => false,

    /*
    |--------------------------------------------------------------------------
    | Allow Carts to have multiple orders associated.
    |--------------------------------------------------------------------------
    |
    | Determines whether the same cart instance will be returned if there is already
    | a completed order associated to the cart which is retrieved in the session.
    | When set to false, if a cart has a completed order, then a new instance
    | of a cart will be returned, even if auto_create is set to false
    |
    */
    'allow_multiple_orders_per_cart' => false,

    /*
    |--------------------------------------------------------------------------
    | Delete cart on logout
    |--------------------------------------------------------------------------
    |
    | Determines whether the cart should be soft deleted when the user logs out.
    | False here: customers are repeat business buyers, so the cart is kept and
    | comes back when they log in again. Logging out still clears the cart from
    | the session, so the next visitor on that browser does not see it.
    |
    */
    'delete_on_forget' => false,
];
