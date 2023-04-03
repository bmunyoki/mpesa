<?php

return [

     //Specify the environment mpesa is running, sandbox or production
     'mpesa_env' => env('MPESA_ENV', 'sandbox'),

    /*-----------------------------------------
    |The App consumer key
    |------------------------------------------
    */
    'consumer_key'   => env('MPESA_CONSUMER_KEY', ''),
    'paybill_consumer_key'   => env('MPESA_PAYBILL_CONSUMER_KEY', ''),
    'till_consumer_key'   => env('MPESA_TILL_CONSUMER_KEY', ''),

    /*-----------------------------------------
    |The App consumer Secret
    |------------------------------------------
    */
    'consumer_secret' => env('MPESA_CONSUMER_SECRET', ''),
    'paybill_consumer_secret' => env('MPESA_PAYBILL_CONSUMER_SECRET', ''),
    'till_consumer_secret' => env('MPESA_TILL_CONSUMER_SECRET', ''),

    /*-----------------------------------------
    |The paybill number or till
    |------------------------------------------
    */
    'paybill' => env('MPESA_PAYBILL', ''),
    'till' => env('MPESA_TILL', ''),

    /*-----------------------------------------
    |Lipa Na Mpesa (till) store number
    |------------------------------------------
    */
    
    'store_number'  => env('MPESA_STORE_NUMBER', ''),

    /*-----------------------------------------
    |Lipa Na Mpesa Online Passkey
    |------------------------------------------
    */
    'passkey' => env('MPESA_PASSKEY', ''),


    /*-----------------------------------------
    |Test phone Number
    |------------------------------------------
    */
    'test_msisdn ' => '254728354249',

    /*-----------------------------------------
    |Lipa na Mpesa Online callback url
    |------------------------------------------
    */
    'lnmo_callback_url' => env('MPESA_LNMO_CALLBACK', ''),

     /*-----------------------------------------
    |C2B  Validation url
    |------------------------------------------
    */
    'c2b_validate_callback' => env('MPESA_VALIDATE_CALLBACK', ''),

    /*-----------------------------------------
    |C2B confirmation url
    |------------------------------------------
    */
    'c2b_confirm_callback' => env('MPESA_CONFIRM_CALLBACK', ''),

    
    'pull_callback_url' => env('MPESA_PULL_CALLBACK', ''),

];
