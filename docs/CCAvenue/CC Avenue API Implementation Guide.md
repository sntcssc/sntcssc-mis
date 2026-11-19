# CC Avenue API Implementation Guide

# Overview
    The CCAvenue API is designed to enable you to interact securely with our API from your client-side web application. You can get XML, JSON or String responses from the API, including errors.

    You need to have an active account to initiate an API call to the CCAvenue payment gateway.

# API Authentication
    Merchant needs to have an active account to initiate an API call to the CCAvenue payment gateway. Merchants will have to log in to their CCAvenue M.A.R.S account and get the authentication credentials for initiating API calls.

    Merchant must provide CCAvenue with the public IP address from where the API calls will be initiated. API calls will work only after CCAvenue registers the IP address provided.

    Log in to your CCAvenue M.A.R.S account, under Settings tab -> API Keys page; copy the following credentials:
        1. Merchant ID
        2. Access Code
        3. Encryption Key

# Encryption of request for API calls
    The requests sent to CCAvenue will contain the parameters mentioned in the table below. enc_request has to be encrypted using AES similar to the method used for real-time transaction. The encryption key is mapped to the access code as mentioned in the API Authentication section.

    | Name | Description |
    | --- | --- |
    | `enc_request` (required) | AES encrypted request data. |
    | `access_code` (required) | This is the access code for your application. You must send this with each request. |
    | `command` (required) | This is the command to access the API Calls. You must send this with each request. |
    | `request_type` (required) | API requests are accepted in XML, JSON, or String formats. You need to specify the request type. |
    | `response_type` (optional) | API returns XML, JSON, or String responses. If left blank, the response will be in the same format as the request. |
    | `version` (required) | This is the version to access the API based on version calls. Current value is `1.1`. |

    Example:
    enc_request=63957FB55DD6E7B968A7588763E08B240878046EF2F520C44BBC63FB9CCE726209A4734877F5904445591304ABB2F5E598B951E39EAFB9A24584B00590ADB077ADE5E8C444EAC5A250B1EA96F68D22E44EA2515401C2CD753DBA91BD0E7DFE7341BE1E7B7550&access_code=8JXENNSSBEZCU8KQ&command=confirmOrder&request_type=XML&response_type=XML&version=1.1

# Decryption of response for API calls
    The response received from CCAvenue will contain the parameters mentioned in the table below. enc_response when encrypted will have to be decrypted using AES similar to the method used for real-time transactions. The encryption key is mapped to the access code as mentioned in the API Authentication section.

    | Name | Description |
    | --- | --- |
    | `enc_response` | AES encrypted response containing the format as per `response_type`. |
    | `enc_error_code` | Contains the value if the status is `"1"`. Please refer to the below table for the `enc_error_code`. |
    | `status` | Indicates whether the call was successful or not. If the value of this parameter is `"1"`, you do not need to decrypt `enc_response`, as it will contain the plain error message. |

    Note:- Please refer to the below table for enc_response value when the status value is “1”.

    Example:
    Successful: status=0&enc_response=63957FB55DD6E7B968A7588763E08B240878046EF2F520C44BBC63FB9CCE726209A473457E6B13721EC6D05ED13A0483ACFDD6F11F284AE79755D47E79687478F93CFCD3CD97510B67B961CDB5279F209F5C451F3039696F13C990B963854C8CADF730

    Error:
    status=1&enc_response=Access_code: Invalid Parameter&enc_error_code=51407.


# API Calls:

    ## Confirm

        The Confirm API call allows you to confirm pending orders. Only confirmed orders are settled into the merchant's account.

        An order older than 5 days is automatically cancelled. Once an order has been auto-cancelled by the system, it cannot be confirmed.

        You also have the option to confirm only part of an order. This can be done only once for each order. The balance funds will be refunded to the credit card, debit card, or net banking account that was originally charged.

        ### Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `enc_request` (required) | AES encrypted string containing request parameters. |  |
        | `access_code` (required) | This is the unique CCAvenue access code generated when the merchant registered their IP address. You must send this with each request. |  |
        | `request_type` (required) | API requests are accepted in XML, JSON, or STRING formats. You need to specify the request type. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `response_type` (optional) | API returns responses in XML, JSON, or STRING formats. If left blank, the response will be in the same format as the request. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `Command` (required) | Specifies the API call. You must send this with each request. | Possible value: `confirmOrder`. |
        | `reference_no` (required) | The unique CCAvenue reference number for the transaction. | `Numeric(25)` |
        | `amount` (required) | The transaction amount to be captured. Amount can be the full or partial transaction amount. | `Decimal(12,2)` |

        ### Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_List>
            <order reference_no="123456789" amount="1.00" />
            <order reference_no="123456789" amount="2.00" />
        </Order_List>
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example JSON Request

        Sample Code

        json

        ```json
        {
            "order_List": [
                {
                    "reference_no": "203000099429",
                    "amount": "1.00"
                },
                {
                    "reference_no": "203000104640",
                    "amount": "1.00"
                }
            ]
        }
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example STRING Request

        Format:

        text

        ```text
        reference_no$amt|reference_no$amt|reference_no$amt|
        ```

        Example:

        text

        ```text
        203000099429$1.00|203000104640$1.00|
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `status` | Indicates whether the API call was successful or not. | `0` denotes that the API call was successful. `1` denotes that the API call failed. If `status` is `1`, you do not need to decrypt `enc_response`, as it will contain a plain-text error message. |
        | `enc_response` | AES encrypted response containing the format specified by `response_type`. |  |
        | `success_count` | The merchant can check the number of successfully processed transaction confirmation records. | `Numeric`. Range: `0 <= success_count <= Number of orders to be confirmed`. |
        | `reference_no` | The unique CCAvenue reference number for the transaction. | `Numeric(25)` |
        | `reason` | Failure reason associated with the given unique reference number. | `String`. Refer to the failure message table. |
        | `error_code` | Error code for the failure. | `String`. Refer to the failure message table. |

        ### Example XML Response

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Result error_code="">
            <error_desc></error_desc>
            <success_count>0</success_count>
            <failed_List>
                <failed_order
                    error_code="51304"
                    reason="Invalid order/tracking id"
                    reference_no="123456788"
                />
                <failed_order
                    error_code="51206"
                    reason="Order List: Invalid Parameter"
                    reference_no="123456788"
                />
            </failed_List>
        </Order_Result>
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example JSON Response

        Sample Code

        json

        ```json
        {
            "failed_List": [
                {
                    "reference_no": "123456788",
                    "reason": "Invalid order/tracking id",
                    "error_code": "51304"
                },
                {
                    "reference_no": "123456788",
                    "reason": "Order List: Invalid Parameter",
                    "error_code": "51206"
                }
            ],
            "error_desc": "",
            "success_count": 0,
            "error_code": ""
        }
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example STRING Response

        Format:

        text

        ```text
        success_count|error_code$reference_no$reason^error_code$reference_no$reason|
        ```

        Example:

        text

        ```text
        0|51304 $123456788$ Invalid order/tracking id ^51206$123456788$Order List: Invalid Parameter|
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

    ## Cancel

        The Cancel API call allows you to cancel pending orders.

        An order older than 5 days is automatically cancelled. Once an order has been cancelled or auto-cancelled by the system, it cannot be confirmed.

        Funds will be refunded to the credit card, debit card, or net banking account that was originally charged.

        ### Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `enc_request` (required) | AES encrypted request data. |  |
        | `access_code` (required) | The unique CCAvenue access code generated when the merchant registered their IP address. You must send this with each request. |  |
        | `request_type` (required) | API requests are accepted in XML, JSON, or STRING formats. You need to specify the request type. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `response_type` (optional) | API returns responses in XML, JSON, or STRING format. If left blank, the response will be in the same format as the request. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `Command` (required) | Specifies the API call. You must send this with each request. | Possible value: `cancelOrder`. |
        | `reference_no` (required) | The unique CCAvenue reference number for the transaction. | `Numeric(25)` |
        | `amount` (required) | Transaction amount to be captured. Amount can be the full or partial transaction amount. | `Decimal(12,2)` |

        ### Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_List>
            <order reference_no="123456788" amount="1.00" />
            <order reference_no="123456789" amount="2.00" />
        </Order_List>
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example JSON Request

        Sample Code

        json

        ```json
        {
            "order_List": [
                {
                    "reference_no": "203000099429",
                    "amount": "1.00"
                },
                {
                    "reference_no": "203000099429",
                    "amount": "1.00"
                }
            ]
        }
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example STRING Request

        Format:

        text

        ```text
        reference_no$amt|reference_no$amt|
        ```

        Example:

        text

        ```text
        203000099429$1.00|203000104640$101.23|
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `status` | Indicates whether the API call was successful or not. | `0` denotes that the API call was successful. `1` denotes that the API call failed. If `enc_response` is plain text, it represents the error message. If `status` is `1`, you do not need to decrypt `enc_response`, as it contains the plain error message. |
        | `enc_response` | AES encrypted response containing the format specified by `response_type`. |  |
        | `success_count` | The merchant can check the number of successfully processed transaction cancellation records. | `Numeric`. Range: `0 <= success_count <= Number of orders for the cancel request`. |
        | `reference_no` | The unique CCAvenue reference number for the transaction. | `Numeric(25)` |
        | `reason` | Failure reason for the cancel request associated with the unique CCAvenue reference number. | `String`. Refer to the failure message table. |
        | `error_code` | Error code for the failure reason. | `String`. Refer to the failure message table. |

        ### Example XML Response

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Result error_code="">
            <error_desc></error_desc>
            <success_count>0</success_count>
            <failed_List>
                <failed_order
                    error_code="51304"
                    reason="Invalid order/tracking id"
                    reference_no="123456788"
                />
                <failed_order
                    error_code="51206"
                    reason="Order List: Invalid Parameter"
                    reference_no="123456788"
                />
            </failed_List>
        </Order_Result>
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example JSON Response

        Sample Code

        json

        ```json
        {
            "failed_List": [
                {
                    "reference_no": "123456788",
                    "reason": "Invalid order/tracking id",
                    "error_code": "51304"
                },
                {
                    "reference_no": "123456788",
                    "reason": "Order List: Invalid Parameter",
                    "error_code": "51206"
                }
            ],
            "error_desc": "",
            "success_count": 0,
            "error_code": ""
        }
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example STRING Response

        Format:

        text

        ```text
        success_count|error_code$reference_no$reason^error_code$reference_no$reason|
        ```

        Example:

        text

        ```text
        0|51304 $123456788$ Invalid order/tracking id ^51206$123456788$Order List: Invalid Parameter|
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

    ## Refund

        The Refund API call allows you to refund an order/transaction that has previously been executed but not yet refunded.

        Funds will be refunded to the credit card, debit card, or net banking account that was originally charged.

        ### Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `enc_request` (required) | AES encrypted request data. |  |
        | `access_code` (required) | The unique CCAvenue access code generated when the merchant registered their IP address. You must send this with each request. |  |
        | `request_type` (required) | API requests are accepted in XML, JSON, or STRING formats. You need to specify the request type. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `response_type` (optional) | API returns XML, JSON, or STRING responses. If left blank, the response will be in the same format as the request. | Possible values: `XML`, `JSON`, or `STRING`. |
        | `command` (required) | The unique name that specifies the API call. You must send this with each request. | Possible value: `refundOrder`. |
        | `reference_no` (required) | The unique CCAvenue reference number for the transaction. | `Numeric(25)` |
        | `refund_amount` (required) | Transaction amount to be refunded. Amount can be the full or partial transaction amount. | `Decimal(12,2)` |
        | `refund_ref_no` (required) | The unique reference number shared by the merchant to refund the captured transaction amount. | `AlphaNumeric(30)` |

        ### Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Refund_Order_Query
            reference_no="1236547"
            refund_amount="1.0"
            refund_ref_no="API1234">
        </Refund_Order_Query>
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example JSON Request

        Sample Code

        json

        ```json
        {
            "reference_no": "1236547",
            "refund_amount": "1.0",
            "refund_ref_no": "API1234"
        }
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Example STRING Request

        Format:

        text

        ```text
        reference_no|refund_amount|refund_ref_no|
        ```

        Example:

        text

        ```text
        203000094245|1.00|API1234|
        ```

        Note: You must encrypt the above request and store it in the `enc_request` parameter before sending it to CCAvenue. Refer to the encryption section.

        ### Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | `status` | Indicates whether the API call was successful or not. | `0` denotes that the API call was successful. `1` denotes that the API call failed. If `enc_response` is plain text, it represents the error message. If `status` is `1`, you do not need to decrypt `enc_response`, as it contains the plain error message. |
        | `enc_response` | AES encrypted response containing the format specified by `response_type`. |  |
        | `refund_status` | Specifies whether the given refund request is going to succeed or fail. | `Numeric`. Value `0` denotes that the refund was successful. Value `1` denotes that the refund transaction failed. |
        | `reason` | Failure reason if the refund request fails for the transaction. | `String`. Refer to the failure message table. |
        | `error_code` | Error code for the failure reason. | `String`. Refer to the failure message table. |

        ### Example XML Response

        Sample Code

        Success Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Refund_Order_Result
            reason=""
            refund_status="0"
            error_code=""/>
        ```

        Failure Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Refund_Order_Result
            reason="Refund not allowed."
            refund_status="1"
            error_code="51310"/>
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example JSON Response

        Sample Code

        Success Response:

        json

        ```json
        {
            "reason": "",
            "error_code": "",
            "refund_status": 0
        }
        ```

        Failure Response:

        json

        ```json
        {
            "reason": "Refund not allowed.",
            "error_code": "51310",
            "refund_status": 1
        }
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

        ### Example STRING Response

        Success Response:

        Format:

        text

        ```text
        refund_status|
        ```

        Example:

        text

        ```text
        0|
        ```

        Failure Response:

        Format:

        text

        ```text
        refund_status|error_code|reason|
        ```

        Example:

        text

        ```text
        1|51310|Refund not allowed.|
        ```

        Note: You must decrypt the response received in the `enc_response` parameter. Refer to the decryption section.

    # Status

        The Status API call can be used to ascertain the status of transactions/orders. You can use this call if you have not received status/information for a transaction request. It can also be used as an additional security measure to reconfirm the parameters posted back.

        ## Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | enc\_request (required) | AES encrypted request data |  |
        | access\_code (required) | The unique CCAvenue access code which is generated when merchant registered their IP address. You must send this with each request. |  |
        | request\_type (required) | API requests are accepted in XML, JSON or String formats. You need to specify the request type. | Possible value for request\_type is “XML” or “JSON” or “STRING”. |
        | response\_type (optional) | API returns responses in XML, JSON or String format. If left blank, the response will be in the same format as the request. | Possible value for response\_type is “XML” or “JSON” or “STRING”. |
        | command (required) | Command value specifies the API calls. You must send this with each request. | Value is “orderStatusTracker”. |
        | reference\_no (conditional) | CCAvenue reference no. allocated to the transaction. Reference number is required if you do not share order\_no. | Numeric(25). |
        | order\_no (conditional) | This is the merchant reference number for the transaction. Order number is required if you do not share reference\_no. | AlphaNumeric with special characters (hyphen and underscore)(40). |

        ## Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Status_Query order_no="33231644" reference_no="225013271813"/>
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example JSON Request

        Sample Code

        json

        ```json
        {
        "reference_no": "225013271813",
        "order_no": "33231644"
        }
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example STRING Request

        Format:

        text

        ```text
        reference_no|order_no|
        ```

        Example:

        text

        ```text
        225013271813|33231644|
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | status | This states whether the call was successful or not. If the value of this parameter is “1” then you need not decrypt the enc\_response as it will contain the plain error message. | Value “0” denotes that the API call is successful. Value “1” denotes that the API call has failed. If enc\_response is plain text, it represents the error message. |
        | enc\_response | AES encrypted response containing format as per response\_type |  |
        | order\_amt | Amount for the transaction. | Decimal(12,2). |
        | order\_bill\_address | Order billing address details for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315) |
        | order\_bill\_city | Order billing City name for the order. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_bill\_country | Order billing country for the order. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_bill\_email | Email Address of the Order for notifications. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_bill\_name | Order billing name for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_bill\_state | Order billing state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_bill\_tel | Order billing telephone no for the order. | Numeric(10) |
        | order\_bill\_zip | Order billing address’s pin code for the order. Possible value for zip is AlphaNumeric with special characters (hyphen and space) (15). |  |
        | order\_capt\_amt | Captured amount for the transaction. Capture amount can be the full or partial transaction amount. | Decimal(12,2). |
        | order\_curr | Possible order Currency in which merchant processed the transaction. | String. Examples: INR - Indian Rupee, USD - United States Dollar, SGD - Singapore Dollar, GBP - Pound Sterling, EUR - Euro, official currency of Eurozone. |
        | order\_date\_time | Order generated Date &Time. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_device\_type | This is the type of device using which the transaction was processed. | Possible value for device type is IVRS/MOB/PC. |
        | order\_discount | This is the Discount Value for the Order No. | Decimal(12,2). |
        | order\_fee\_flat | Flat Fee for the Order No. | Decimal(12,2). |
        | order\_fee\_perc | Provides the percentage fee for the same order No. | Decimal(12,2). |
        | order\_fee\_perc\_value | This attribute provides the percentage fee Value for the same order No. | Decimal(12,2). |
        | order\_fraud\_status | Specify whether orders are valid or not. | String. Possible Values are: 1) Value “High” denotes “High Risk” 2) Value “Low” denotes “Low Risk” 3) Value “NR” denotes “No Risk” 4) Value “GA” denotes “Go Ahead” 5) Value “NA” denotes “Not Applicable” |
        | order\_gross\_amt | Total transaction amount. | Decimal(12,2). |
        | order\_ip | Customer IP Address (i.e. from where the transaction is being initiated) | IP V-4 Supported. |
        | order\_no | Order No for the transaction. | AlphaNumeric with special characters(hyphen and underscore)(40). |
        | order\_notes | Order information you wish to provide. | AlphaNumeric with special characters (space, comma, dot, hyphen and underscore)(60). |
        | order\_ship\_address | Shipping Address for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315). |
        | order\_ship\_city | Shipping city name for the orders. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_ship\_country | Shipping country name for the orders. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_ship\_email | Shipping email ID for the notifications of the transaction. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_ship\_name | Shipping Name of the Customer for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_ship\_state | Shipping state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_ship\_tel | Telephone no for notifications of the transaction. | Numeric(10). |
        | order\_ship\_zip | Order shipping address pin code for the order. | Possible value for zip is AlphaNumeric with special characters(hyphen and space) (15). |
        | order\_status | Status of the order. It can be single or multiple. | String. Possible values are: Aborted (transaction is cancelled by the User; Auto-Cancelled (transaction has not confirmed within 5 days hence auto cancelled by system); Auto-Reversed (two identical transactions for same order number, both were successful at bank's end but we got response for only one of them, then next day during reconciliation we mark one of the transaction as auto reversed); Awaited (transaction is processed from billing shipping page but no response is received); Cancelled (transaction is cancelled by merchant); Chargeback(); Invalid(Transaction sent to CCAvenue with Invalid parameters, hence could not be processed further); Fraud (we update this during recon, the amount is different at bank’s end and at CCAvenue due to tampering); Initiated (transaction just arrived on billing shipping page and not processed further); Refunded (Transaction is refunded.); Shipped (transaction is confirmed); Successful; System refund (Refunded by CCAvenue for various findings of reversals by CCAvenue); Unsuccessful (transaction is not successful due to ). |
        | order\_status\_date\_time | This is the latest date and time when the order status is modified. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_TDS | Amount of TDS (tax deducted at source) for the Transaction. | Decimal (13,4). |
        | order\_tax | Tax Amount for the Transaction. | Decimal (13,4). |
        | reference\_no | CCAvenue reference no. allocated to the transaction. | Numeric(25). |
        | order\_bank\_ref\_no | Unique reference number shared by the Bank after successful transaction. | Numeric(25). |
        | order\_bank\_response | Description about the transaction shared by the bank after transaction. | String |
        | order\_gtw\_id | Unique payment option - Bank name. | Alphabet(6) |
        | order\_card\_name | Specify the card name for the transaction. | Possible value for card name is VISA","MASTERCARD","AMEX","JCB","ECRD","DINERS CLUB","DSNV","CTBL","CVMS". |
        | order\_option\_type | Specify the payment option type for the order. | String. Possible value for payment option type is OPTCASHC-Cash card, OPTCRDC -Credit Card, OPTDBCRD-Debit Card, OPTEMI-EMI, OPTIVRS-IVRS, OPTMOBP-MobilePayments, OPTNBK-Net Banking. |
        | page\_count | Total pages available based on no\_of\_records in the request | Example: no\_of\_records sent in request was 100. total\_records matching the lookup criteria were 1000. page\_count will be 10 (total\_records / no\_of\_records) rounded to the ceiling. |
        | total\_records | Total no.of orders matching the lookup criteria |  |
        | error\_desc | Reason if search criteria did not find the orders for the transactions. | String. Please refer to the below table for the failure message. |
        | error\_code | Error code for Failure reason. | String. Please refer to the below table for the failure message. |

        ## Example XML Response

        Sample Code

        Success Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Status_Result error_code="">
        <error_desc></error_desc>
        <order_TDS>0.0</order_TDS>
        <order_amt>1.0</order_amt>
        <order_bank_ref_no>289049</order_bank_ref_no>
        <order_bank_response>Approved</order_bank_response>
        <order_bill_address>Room no 1101, near Railway station</order_bill_address>
        <order_bill_city>Indore</order_bill_city>
            <order_bill_country>India</order_bill_country>
            <order_bill_email>xxxxx.xxx@xxxxx.xxxx</order_bill_email>
            <order_bill_name>Shashi</order_bill_name>
            <order_bill_state>MP</order_bill_state>
            <order_bill_tel>1234567890</order_bill_tel>
            <order_bill_zip>425001</order_bill_zip>
            <order_capt_amt>0.0</order_capt_amt>
            <order_card_name>Amex</order_card_name>
            <order_currncy>INR</order_currncy>
            <order_date_time>2015-04-13 10:59:05.517</order_date_time>
            <order_device_type>PC</order_device_type>
            <order_discount>0.0</order_discount>
            <order_fee_flat>0.0</order_fee_flat>
            <order_fee_perc>4.0</order_fee_perc>
            <order_fee_perc_value>0.04</order_fee_perc_value>
            <order_fraud_status>NA</order_fraud_status>
            <order_from_date></order_from_date>
            <order_gross_amt>1.0</order_gross_amt>
            <order_gtw_id>PGT</order_gtw_id>
            <order_ip>192.168.2.182</order_ip>
            <order_name></order_name>
            <order_no>66885810</order_no>
            <order_notes>order will be shipped</order_notes>
            <order_option_type>OPTCRDC</order_option_type>
            <order_ship_address>room no.701 near bus stand</order_ship_address>
            <order_ship_city>Hyderabad</order_ship_city>
            <order_ship_country>India</order_ship_country>
            <order_ship_name>Chaplin</order_ship_name>
            <order_ship_state>Andhra</order_ship_state>
            <order_ship_tel>1234567890</order_ship_tel>
            <order_ship_zip>425001</order_ship_zip>
            <order_status>Successful</order_status>
            <order_status_date_time>2015-04-13 10:59:53.217</order_status_date_time>
            <order_tax>0.0049</order_tax>
            <order_to_date></order_to_date>
            <reference_no>204000136232</reference_no>
            <status>0</status>
        </Order_Status_Result>
        ```

        Failure Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Status_Result error_code="51313">
            <error_desc>No record found</error_desc>
            <status>1</status>
        </Order_Status_Result>
        ```

        Note: You will have to decrypt the above response from "enc\_response"" parameter. Kindly refer to the decryption section.

        ## Example JSON Response

        Sample Code

        Success Response:

        json

        ```json
        {
            "reference_no":204000136232,
            "order_no":"66885810",
            "order_currncy":"INR",
            "order_amt":1.0,
            "order_date_time":"2015-04-13 10:59:05.517",
            "order_bill_name":"Shashi",
            "order_bill_address":"Room no 1101,
            near Railway station Ambad",
            "order_bill_zip":"425001",
            "order_bill_tel":"1234567890",
            "order_bill_email":"chandrakant.patil@avenues.info",
            "order_bill_country":"India",
            "order_ship_name":"Chaplin",
            "order_ship_address":"room no.701 near bus stand",
            "order_ship_country":"India",
            "order_ship_tel":"1234567890",
            "order_bill_city":"Indore",
            "order_bill_state":"MP",
            "order_ship_city":"Hyderabad",
            "order_ship_state":"Andhra",
            "order_ship_zip":"425001",
            "order_notes":"order will be shipped",
            "order_ip":"192.168.2.182",
            "order_status":"Successful",
            "order_fraud_status":"NA",
            "order_status_date_time":"2015-04-13 10:59:53.217",
            "order_capt_amt":0.0,
            "order_card_name":"Amex",
            "order_fee_perc":4.0,
            "order_fee_perc_value":0.04,
            "order_fee_flat":0.0,
            "order_gross_amt":1.0,
            "order_discount":0.0,
            "order_tax":0.0049,
            "order_bank_ref_no":"289049",
            "order_gtw_id":"PGT",
            "order_bank_response":"Approved",	
            "order_option_type":"OPTCRDC",
            "order_TDS":0.0,
            "order_device_type":"PC",	
            "status":0,
            "error_desc":"",
            "error_code":"",
        }
        ```

        Failure Response:

        json

        ```json
        {
            "error_desc":"No record found",
            "error_code":"51313",
            "status":1
        }
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ## Example STRING Response

        Format:

        text

        ```text
        status|order_status|reference_no|order_bank_ref_no|order_bank_response| order_bill_name|order_bill_email|order_bill_address|order_bill_city|order_bill_state|order_bill_country|order_bill_telephone_no|order_bill_city_zip|order_card_name|order_currency|order_date_time|order_delivery_details|order_device_type|order_fraud_status|order_gateway_id|order_iP|order_no| order_notes|order_option_type|order_shiping_name|order_ship_email|order_ship_address|order_ship_city|order_ship_state|order_ship_country|order_ship_telephone_no|order_ship_zip|order_status_date_time|order_TDS|order_amount|order_capture_amount|order_discount|order_fee_flat||order_fee_perc|order_fee_perc_value|order_gross_amount|order_tax|
        ```

        Example:

        text

        ```text
        0|Successful|204000136232|289049|Approved|Shashi|xxxxx.xxx@xxxxx.xxxx|Room no 1101, near Railway station Ambad|Indore|MP|India|1234567890|425001|Amex|INR|2015-04-13 10:59:05.517||PC|NA|PGT|192.168.2.182|66885810|order will be shipped|OPTCRDC|Chaplin||room no.701 near bus stand|Hyderabad|Andhra|India|1234567890|425001|2015-04-13 10:59:53.217|0.0|1.0|0.0|0.0|0.0|4.0|0.04|1.0|0.0049|
        ```

        Failure Response:

        Format:

        text

        ```text
        statud|error_code|error_desc|
        ```

        Example:

        text

        ```text
        1|51313|No records found.|
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

    # Order Lookup

        The Lookup API call can be used to extract transaction details for a certain set of transactions. The functionality works similar to a search feature.

        ## Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | enc\_request (required) | AES encrypted request data |  |
        | access\_code (required) | The unique CCAvenue access code which is generated when the merchant registered their IP address. You must send this with each request. |  |
        | request\_type (required) | API requests are accepted in XML, JSON or String formats. You need to specify the request type. | Possible value for request\_type is “XML” or “JSON” or “STRING”. |
        | response\_type (optional) | API returns responses in XML, JSON or String formats. If left blank, the response will be in the same format as the request. | Possible value for response\_type is “XML” or “JSON” or “STRING”. |
        | Command (required) | Command value specifies the API calls. You must send this with each request. | Possible value for this API call is “orderLookup”. |
        | reference\_no (optional) | The unique CCAvenue reference number for the transaction. | Numeric(25). |
        | from\_date (required) | Provide the Start Date to find the orders list. | Date must be in IST(dd-mm-yyyy )format. |
        | to\_date (optional) | Provide the end date to search the orders between from date and to date. It should be greater than or equal to from date. | Date must be in IST(dd-mm-yyyy) format. |
        | order\_currency (optional) | Currency in which you processed the transaction. You can send the multiple currencies format. | String. Examples: INR - Indian Rupee, USD - United States Dollar, SGD - Singapore Dollar, GBP - Pound Sterling, EUR - Euro, official currency of Eurozone. Multiple currency format: INR\|USD\|GBP in JSON & XML request type but INR$USD$GBP in STRING request type. |
        | order\_email (optional) | Email address used to purchase the order. | Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_fraud\_status (optional) | Specify whether orders are valid or not. | String. Possible Values are: 1) Value “High” denotes “High Risk” 2) Value “Low” denotes “Low Risk” 3) Value “NR” denotes “No Risk” 4) Value “GA” denotes “Go Ahead” 5) Value “NA” denotes “Not Applicable”. |
        | order\_min\_amount (optional) | Minimum amount limit for search criteria for the transaction. | Decimal(12,2). |
        | order\_max\_amount (optional) | Maximum amount limit for search criteria for the transaction. | Decimal(12,2). |
        | order\_name (optional) | The customer name for the transaction. | Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_no (optional) | The unique merchant order no for the transaction. | AlphaNumeric with special characters(hyphen and underscore)(40). |
        | order\_payment\_type (optional) | Payment Mode for the transaction. It can be single or multiple. | String. Below are the Possible Values: 1) CASHC (Cash Card Payment Type) 2) CRDC (Credit Card Payment Type) 3) DBCRD (Debit Card Payment Type) 4) MOBP (Mobile Payment Type) 5) NBK (Net Banking). Multiple values format: MOBP\|NBK for JSON & XML request type but MOBP$NBK for STRING request type. |
        | order\_status (optional) | Status of the order. It can be single or multiple. | String. Possible values are: Aborted (transaction is cancelled by the User); Auto-Cancelled (transaction has not confirmed within 5 days hence auto cancelled by system); Auto-Reversed (two identical transactions for same order number, both were successful at bank's end but we got response for only one of them, then next day during reconciliation we mark one of the transaction as auto reversed); Awaited (transaction is processed from billing shipping page but no response is received); Cancelled (transaction is cancelled by merchant); Chargeback(); Invalid(Transaction sent to CCAvenue with Invalid parameters, hence could not be processed further); Fraud (we update this during recon, the amount is different at bank’s end and at CCAvenue due to tampering); Initiated (transaction just arrived on billing shipping page and not processed further); Refunded (Transaction is refunded.); Shipped (transaction is confirmed); Successful; System refund (Refunded by CCAvenue for various find of reversals by CCAvenue); Unsuccessful (transaction is not successful due to ). |
        | order\_type (optional) | Type of the order. | String. Different types of Orders: 1) OT-INV denotes “Invoice” 2) OT-ORD denotes “Orders” 3) OT-ORDSC denotes “Shopping Cart Orders” 4) OT-PPAY denotes” Phone Pay” 5) OT-SNIP denotes “SNIP orders” 6) OT-STO denotes “Storefront” 7) OT-ORDSI denotes “SI” 8) OT-BILL denotes “Bill\\A0Payments” 9) OT-LINK denotes “Link Pay” 10) OT-POS denotes “POS” 11) OT-TAPPAY denotes “Tap Pay” 12) OT-QR denotes “Offline QR” 13) OT-FORM denotes “Forms”. |
        | order\_bill\_tel (optional) | Customer mobile number for the transaction. | Numeric(10). |
        | page\_number (required) | A limited number of records are shared as part of the response. The total records & number of pages are shared as part of the response to enable subsequent calls. | Numeric(4). |

        ## Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Lookup_Query>
            <order_no>xxxxxxx</order_no>
            <reference_no>xxxxxxxxx</reference_no>
            <order_email>xxx@xxxxx.com</order_email>
            <order_bill_tel>xxxxxxxxxxx</order_bill_tel>
            <order_country>xxxxx</order_country>
            <from_date>xx-xx-xxxx</from_date>
            <to_date>xx-xx-xxxx</to_date>
            <order_max_amount>xx.xx</order_max_amount>
            <order_min_amount>xx.xx</order_min_amount>
            <order_status>xxxxxxx</order_status>
            <order_fraud_status>xxxx</order_fraud_status>
            <order_currency>xxx</order_currency>
            <order_type>xx-xxx</order_type>
            <order_payment_type>xxxxxx</order_payment_type>
            <page_number>1</page_number>
        </Order_Lookup_Query>
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example JSON Request

        Sample Code

        json

        ```json
        {
        "order_no": "xxx",
        "reference_no":"xxxxx",
        "order_email": "xxx@xxxx.com",
        "order_bill_tel": "xxxxxxxxx",
        "order_country": "xxxxxxx",
        "from_date": "xx-xx-xxxx",
        "to_date": "xx-xx-xxxx",
        "order_max_amount": "xx.xx",
        "order_min_amount": "xx.xx",
        "order_status": "xxxxx",
        "order_fraud_status": "xxxx",
        "order_currency": "xxx",
        "order_type": "xxxxxx",
        "order_payment_type": "xxxxx",
        "page_number":1
        }
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example STRING Request

        Format:

        text

        ```text
        reference_no|order_no|from_date|to_date|order_status|order_bill_tel|order_country|order_email|order_fraud_status|order_max_amount|order_min_amount|order_name|order_payment_type|order_type|order_currency|page_number|
        ```

        Example:

        text

        ```text
        ||21-03-2015|03-04-2015||||||||||||1|
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | status | This states whether the call was successful or not. If value of this parameter is “1” then you need not decrypt the enc\_response as it will contain the plain error message. | Value “0” denotes that the API call is successful. Value “1” denotes that the API call has failed. If enc\_response is plain text, it represents the error message. |
        | enc\_response | AES encrypted response containing the format as per response\_type |  |
        | order\_amt | Amount for the transaction. | Decimal(12,2). |
        | order\_bill\_address | Order billing address details for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315). |
        | order\_bill\_city | Order billing City name for the order. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_bill\_country | Order billing country for the Order. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_bill\_email | Email Address of the Order for notifications. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot,@)(70). |
        | order\_bill\_name | Order billing name for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_bill\_state | Order billing state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_bill\_tel | Order billing telephone no for the order. | Numeric(10) |
        | order\_bill\_zip | Order billing address pin code for the order. Possible value for zip is AlphaNumeric with special characters (hyphen and space) (15). |  |
        | order\_capt\_amt | Captured amount for the transaction. Captured amount can be full or partial of the transaction amount. | Decimal(12,2). |
        | order\_curr | Possible order Currency in which the merchant processed the transaction. | String. Examples: INR - Indian Rupee, USD - United States Dollar, SGD - Singapore Dollar, GBP - Pound Sterling, EUR - Euro, official currency of Eurozone. |
        | order\_date\_time | Order Generated Date &Time. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_device\_type | This is the type of device using which the transaction was processed. | Possible value for device type is IVRS/MOB/PC. |
        | order\_discount | This is Discount Value for the Order No. | Decimal(12,2). |
        | order\_fee\_flat | Flat Fee for the Order No. | Decimal(12,2). |
        | order\_fee\_perc | Provides the percentage fee for the same order No. | Decimal(12,2). |
        | order\_fee\_perc\_value | This attribute provides the percentage fee Value for the same order No. | Decimal(12,2). |
        | order\_fraud\_status | Specify whether orders are valid or not. | String. Possible Values are: 1) Value “High” denotes “High Risk” 2) Value “Low” denotes “Low Risk” 3) Value “NR” denotes “No Risk” 4) Value “GA” denotes “Go Ahead” 5) Value “NA” denotes “Not Applicable”. |
        | order\_gross\_amt | Total transaction amount. | Decimal(12,2). |
        | order\_ip | Customer IP Address (i.e. from where transaction is being initiated) | IP V-4 Supported. |
        | order\_no | Order No. for the transaction. | AlphaNumeric with special characters(hyphen and underscore)(40). |
        | order\_notes | Order information you wish to provide. | AlphaNumeric with special characters(space, comma, dot, hyphen and underscore)(60). |
        | order\_ship\_address | Shipping Address for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315). |
        | order\_ship\_city | Shipping city name for the orders. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_ship\_country | Shipping country name for the orders. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_ship\_email | Shipping email ID for the notifications of the transaction. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot,@)(70). |
        | order\_ship\_name | Shipping Name of the Customer for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_ship\_state | Shipping state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_ship\_tel | Telephone no for notifications of the transaction. | Numeric(10). |
        | order\_ship\_zip | Order shipping address pin code for the order. | Possible value for zip is AlphaNumeric with special characters(hyphen and space) (15). |
        | order\_status | Status of the order. It can be single or multiple. | String. Possible values are: Aborted (transaction is cancelled by the User); Auto-Cancelled (transaction has not confirmed within 5 days hence auto cancelled by system); Auto-Reversed (two identical transactions for same order number, both were successful at bank's end but we got response for only one of them, then next day during reconciliation we mark one of the transaction as auto reversed); Awaited (transaction is processed from billing shipping page but no response is received); Cancelled (transaction is cancelled by merchant); Chargeback(); Invalid(Transaction sent to CCAvenue with Invalid parameters, hence could not be processed further); Fraud (we update this during recon, the amount is different at bank’s end and at CCAvenue due to tampering); Initiated (transaction just arrived on billing shipping page and not processed further); Refunded (Transaction is refunded.); Shipped (transaction is confirmed); Successful; System refund (Refunded by CCAvenue for various find of reversals by CCAvenue); Unsuccessful (transaction is not successful due to ). |
        | order\_status\_date\_time | This is the latest date and time when order status is modified. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_TDS | Amount of TDS (tax deducted at source) for the Transaction. | Decimal(13,4). |
        | order\_tax | Tax Amount for the Transaction. | Decimal (13,4) |
        | reference\_no | CCAvenue reference no allocated to the transaction. | Numeric(25). |
        | order\_bank\_ref\_no | Unique reference number shared by Bank after the successful transaction. | Numeric(25). |
        | order\_bank\_response | Description about the transaction shared by the bank after transaction. | String |
        | order\_gtw\_id | Unique payment option - Bank name. | Alphabet(6) |
        | order\_card\_name | Specify the card name for the transaction. | Possible value for card name is VISA","MASTERCARD","AMEX","JCB","ECRD","DINERS CLUB","DSNV","CTBL","CVMS". |
        | order\_option\_type | Specify the payment option type for the order. | String. Possible value for payment option type is OPTCASHC-Cash card, OPTCRDC -Credit Card, OPTDBCRD-Debit Card, OPTEMI-EMI, OPTIVRS-IVRS, OPTMOBP-MobilePayments, OPTNBK-Net Banking. |
        | error\_desc | Reason if search criteria did not find the orders for the transactions. | String. Please refer to the below table for the failure message. |
        | error\_code | Error code for Failure reason. | String. Please refer to the below table for the failure message. |
        | page\_count | Total pages available based on no\_of\_records in the request. | Example: no\_of\_records sent in request was 100. total\_records matching the lookup criteria were 1000. page\_count will be 10 (total\_records / no\_of\_records) rounded to the ceiling. |
        | total\_records | Total no.of orders matching the lookup criteria |  |

        ## Example XML Response

        Sample Code

        Success Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Lookup_Result error_code="">
        <error_desc></error_desc>
        <order_Status_List>
            <order order_TDS="0.0"
                order_amt="1.0"
                order_bank_response="Invalid Credentials"
                order_bill_address="Room no 1101, near Railway station Ambad"
                order_bill_city="Indore"
                order_bill_country="India"
                order_bill_email="xxxxx.xxx@xxxxx.xxxx"
                order_bill_name="Shashi"
                order_bill_state="MP"
                order_bill_tel="1234567890"
                order_bill_zip="425001"
                order_capt_amt="0.0"
                order_card_name="MasterCard"
                order_currncy="INR"
                order_date_time="2015-03-31 11:20:44.47"
                order_device_type="PC"
                order_discount="0.0"
                order_fee_flat="0.0"
                order_fee_perc="12.0"
                order_fee_perc_value="0.12"
                order_fraud_status="NA"
                order_gross_amt="1.0"
                order_gtw_id="SBI"
                order_ip="192.168.2.182"
                order_no="45289752"
                order_notes="order will be shipped"
                order_option_type="OPTCRDC"
                order_ship_address="Room no 1101, near Railway station Ambad"
                order_ship_city="Indore"
                order_ship_country="India"
                order_ship_name="Shashi"
                order_ship_state="MP"
                order_ship_tel="1234567890"
                order_ship_zip="425001"
                order_status="Unsuccessful"
                order_status_date_time="2015-03-31 11:21:09.99"
                order_tax="0.0148"
                reference_no="204000134595"/>
            </order_Status_List>
            <page_count>1</page_count>
            <total_records>1</total_records>
        </Order_Lookup_Result>
        ```

        Failure Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Order_Lookup_Result error_code="51313">
        <error_desc>No records found</error_desc>
        <page_count>0</page_count>
        <total_records>0</total_records>
        </Order_Lookup_Result>
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ## Example JSON Response

        Sample Code

        Success Response:

        json

        ```json
        {
        "order_Status_List":[{
            "reference_no":204000134595,
            "order_no":"45289752",
            "order_currncy":"INR",
            "order_amt":1.0,
            "order_date_time":"2015-03-31 11:20:44.47",
            "order_bill_name":"Shashi",
            "order_bill_address":"Room no 1101, near Railway station Ambad",
            "order_bill_zip":"425001",
            "order_bill_tel":"1234567890",
            "order_bill_email":"xxxx.xxxx@xxxx.xxxx",
            "order_bill_country":"India",
            "order_ship_name":"Shashi",
            "order_ship_address":"Room no 1101, near Railway station Ambad",
            "order_ship_country":"India",
            "order_ship_tel":"1234567890",
            "order_bill_city":"Indore",
            "order_bill_state":"MP",
            "order_ship_city":"Indore",
            "order_ship_state":"MP",
            "order_ship_zip":"425001",
            "order_notes":"order will be shipped",
            "order_ip":"192.168.2.182",
            "order_status":"Unsuccessful",
            "order_fraud_status":"NA",
            "order_status_date_time":"2015-03-31 11:21:09.99",
            "order_capt_amt":0.0,
            "order_card_name":"MasterCard",
            "order_fee_perc_value":0.12,
            "order_fee_perc":12.0,
            "order_fee_flat":0.0,
            "order_gross_amt":1.0,
            "order_discount":0.0,
            "order_tax":0.0148,
            "order_TDS":0.0,
            "order_gtw_id":"SBI",
            "order_bank_response":"Invalid Credentials",
            "order_option_type":"OPTCRDC",
            "order_device_type":"PC"
            }],
        "page_count":1,
        "total_records":1,
        "error_desc":"",
        "error_code":""
        }
        ```

        Failure Response:

        json

        ```json
        {
            "page_count":0,
            "total_records":0,
            "error_desc":"No records found",
            "error_code":"51313"
        }
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ## Example STRING Response

        Success Response:

        Format:

        text

        ```text
        page_count|total_records|reference_no$order_no$order_amount$order_status$order_bank_ref_no$order_bank_response$order_card_name$order_currancy$order_date_time$order_delivery_details$order_device_type$order_fraud_status$order_gateway_id$order_ip$order_notes$order_option_type$order_bill_name$order_bill_address$order_bill_city$order_bill_state$order_bill_country$order_bill_zip$order_bill_tel$order_bill_email$order_ship_name$order_ship_address$order_ship_city$order_ship_state$order_ship_county$order_ship_zip$order_ship_tel$order_ship_email$order_capture_amount$order_discount$order_gross_amount$order_fee_flat$order_fee_perc|order_fee_perc_value^|
        ```

        Example:

        text

        ```text
        Successful Response: 1|1|204000134595$45289752$1.0$Unsuccessful$$Invalid Credentials$MasterCard$INR$2015-03-31 11:20:44.47$$PC$NA$SBI$192.168.2.182$order will be shipped$OPTCRDC$Shashi$Room no 1101, near Railway station Ambad$Indore$MP$India$425001$9595226054$xxxxx.xxxx@xxxxxx.xxxx$Shashi$Room no 1101, near Railway station Ambad$Indore$MP$India$425001$1234567890$$0.0$0.0$1.0$0.0$12.0$0.12|
        ```

        Failure Response:

        Format:

        text

        ```text
        page_no|total_no_of_records|error_code|error_desc|
        ```

        Example:

        text

        ```text
        0|0|51313|No records found|
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

    # Pending Orders

        Pending API call allows you to find the list of pending orders. Only confirmed orders are settled into the merchant's account. An order older than 5 days is automatically canceled. Once an order has been auto-canceled by the system, it cannot be confirmed.

        You have the option to confirm only part of an order. This can be done only once. The balance funds will be refunded to the credit card or debit card or net banking account that was originally charged.

        ## Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | enc\_request (required) | AES encrypted request data |  |
        | access\_code (required) | The unique CCAvenue access code which was generated when the merchant registered their IP address. You must send this with each request. |  |
        | request\_type (required) | API requests are accepted in XML, JSON or String formats. You need to specify the request type. | Possible value for request\_type is “XML” or “JSON” or “STRING”. |
        | response\_type (optional) | API returns responses in XML, JSON or String formats. If left blank, the response will be in the same format as the request. | Possible value for response\_type is “XML” or “JSON” or “STRING”. |
        | Command (required) | Command value specifies the API calls. You must send this with each request. | Possible value for this API call is “getPendingOrders”. |
        | reference\_no (optional) | The unique CCAvenue reference number for the transaction. | Numeric(25) |
        | order\_no (optional) | The unique Merchant order number for the pending order of the transaction. | AlphaNumeric with special characters(hyphen and underscore)(40). |
        | order\_bill\_name (optional) | Name of the customer to find invoice details after placing orders. | Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_email (optional) | Email id of customer to send mail for the generate invoice transaction. | Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_bill\_tel (optional) | Mobile number of the customer to send the generate invoice process details. | Numeric(10) |
        | order\_fraud\_status (optional) | Type of fraud status. | String. Possible Values are: 1) Value “High” denotes “High Risk” 2) Value “Low” denotes “Low Risk” 3) Value “NR” denotes “No Risk” 4) Value “GA” denotes “Go Ahead” 5) Value “NA” denotes “Not Applicable”. |
        | order\_currency (optional) | Currency in which you processed the transaction. You can send the multiple currencies format. | String. Examples: INR - Indian Rupee, USD - United States Dollar, SGD - Singapore Dollar, GBP - Pound Sterling, EUR - Euro, official currency of Eurozone. Multiple currency format: INR\|USD\|GBP in XML & JSON request type and INR$UDS$GBP in STRING request type. |
        | order\_type (optional) | Type of the order. | String. Different types of Orders: 1) OT-INV denotes “Invoice” 2) OT-ORD denotes “Orders” 3) OT-ORDSC denotes “Shopping Cart Orders” 4) OT-PPAY denotes” Phone Pay” 5) OT-SNIP denotes “SNIP orders” 6) OT-STO denotes “Storefront” 7) OT-ORDSI denotes “SI” 8) OT-BILL denotes “Bill\\A0Payments” 9) OT-LINK denotes “Link Pay” 10) OT-POS denotes “POS” 11) OT-TAPPAY denotes “Tap Pay” 12) OT-QR denotes “Offline QR” 13) OT-FORM denotes “Forms”. |
        | page\_number (required) | A limited number of records are shared as part of the response. The total records & number of pages are shared as part of the response to enable subsequent calls. | Numeric(4) |

        ## Example XML Request

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Pending_Orders_Query>
        <order_no>XXXXXX</order_no>
        <reference_no>XXXXXXX</reference_no>
        <order_bill_name>XXXXX</order_bill_name>
        <order_email>XXXX@XXXX.com</order_email>
        <order_bill_tel>XXXXXXX</order_bill_tel>
        <order_fraud_status>XX|XXXX|XXX</order_fraud_status>
        <order_currency>XXX|XXX|XXX|XXX</order_currency>
        <order_type>XX-XXX|XX-XXX</order_type>
        <page_number>1</page_number>
        </Pending_Orders_Query>
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example JSON Request

        Sample Code

        json

        ```json
        {
        "order_no": "XXXXXXX",
        "reference_no": "XXXXXXX",
        "order_bill_name": "XXXXX",
        "order_email": "XXXX@XXXXX.com",
        "order_bill_tel": "XXXXXXXXXXX",
        "order_fraud_status": "XX|XXXX|XXX",
        "order_currency": "XXX|XXX|XXX|XXX",
        "order_type": "XX|XX-XX",
        "page_number": "1"
        }
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Example STRING Request

        Format:

        text

        ```text
        reference_no|order_no|order_bill_name|order_bill_tel|order_currency$order_currency $order_currency|order_email|order_fraud_status$order_fraud_status $order_fraud_status|order_type$order_type$order_type|page_number|
        ```

        Example:

        text

        ```text
        XXXXXXX|XXXXXX|XXXXX|XXXXXXX|XXX$XXX$XXX$XXX|XXXX@XXXX.com|XX$XXXX$XXX|XX-XXX$XX-XXX|1|
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | status | This states whether the call was successful or not. If the value of this parameter is “1” then you need not decrypt the enc\_response as it will contain the plain error message. | Value “0” denotes that the API call is successful. Value “1” denotes that the API call has failed. If enc\_response is plain text, it represents the error message. |
        | enc\_response | AES encrypted response containing the format as per response\_type |  |
        | order\_amt | Amount for the transaction. | Decimal(12,2). |
        | order\_bill\_address | Order billing address details for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315). |
        | order\_bill\_city | Order billing City name for the order. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_bill\_country | Order billing country for the Order. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_bill\_email | Email Address of the Order for notifications. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_bill\_name | Order billing name for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_bill\_state | Order billing state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_bill\_tel | Order billing telephone no. for the order. | Numeric(10) |
        | order\_bill\_zip | Order billing address’ pin code for the order. Possible value for zip is AlphaNumeric with special characters(hyphen and space) (15). |  |
        | order\_capt\_amt | Captured amount for the transaction. Captured amount can be the full or partial transaction amount. | Decimal(12,2). |
        | order\_curr | Possible order Currency in which merchant processed the transaction. | String. Examples: INR - Indian Rupee, USD - United States Dollar, SGD - Singapore Dollar, GBP - Pound Sterling, EUR - Euro, official currency of Eurozone. |
        | order\_date\_time | Order Generated Date &Time. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_device\_type | This is the type of device using which the transaction was processed. | Possible value for device type is IVRS/MOB/PC. |
        | days\_left | Number of days left to pay order amount. | Numeric(3) |
        | order\_discount | This is Discount Value for the Order No. | Decimal(12,2). |
        | order\_fee\_flat | Flat Fee for the Order No. | Decimal(12,2). |
        | order\_fee\_perc | Provides the percentage fee for the same order No. | Decimal(12,2). |
        | order\_fee\_perc\_value | This attribute provides the percentage fee Value for the same order No. | Decimal(12,2). |
        | order\_fraud\_status | Specify whether the orders are valid or not. | String. Possible Values are: 1) Value “High” denotes “High Risk” 2) Value “Low” denotes “Low Risk” 3) Value “NR” denotes “No Risk” 4) Value “GA” denotes “Go Ahead” 5) Value “NA” denotes “Not Applicable”. |
        | order\_gross\_amt | Total transaction amount. | Decimal(12,2). |
        | order\_ip | Customer IP Address (i.e. from where the transaction is being initiated) | IP V-4 Supported. |
        | order\_no | Order No. for the transaction. | AlphaNumeric with special characters(hyphen and underscore)(40). |
        | order\_notes | Order information you wish to provide. | AlphaNumeric with special characters(space, comma, dot, hyphen and underscore)(60). |
        | days\_left | Number of days left to pay the order amount. | Numeric (4). |
        | order\_bank\_mid | Unique merchant id provided by issuer bank to merchant for the transaction. | Numeric(16). |
        | order\_bank\_ref\_no | Unique reference number shared by Bank after successful transaction. | Numeric(25). |
        | order\_bank\_response | Description about the transaction shared by the bank after transaction. | String |
        | order\_gtw\_id | Unique payment option - Bank name. | Alphabet(6) |
        | order\_card\_name | Specify the card name for the transaction. | Possible value for card name is VISA","MASTERCARD","AMEX","JCB","ECRD","DINERS CLUB","DSNV","CTBL","CVMS". |
        | order\_option\_type | Specify the payment option type for the order. | String. Possible value for payment option type is OPTCASHC-Cash card, OPTCRDC -Credit Card, OPTDBCRD-Debit Card, OPTEMI-EMI, OPTIVRS-IVRS, OPTMOBP-MobilePayments, OPTNBK-Net Banking. |
        | order\_card\_type | Specify the card type used to pay order amount. | String. Possible value for card type is Cash card, Credit Card, Debit Card, EMI, IVRS, Mobile Payments, Net Banking. |
        | order\_risk\_mitigation | Specify whether the current order is under risk mitigation or not. | String(1). Possible value is “Y” or ”N”. |
        | order\_tax | Tax Amount for the Transaction. | Decimal (13,4) |
        | order\_tran\_status | Transaction status for the order coming from bank's end. | String. Possible value is TS-AUTA- Authorization Awaited, TS-AUTC- Authorization confirm, TS-AUTCR- Authorization confirmed on reconciliation, TS-AUTD- Authorization Declined, TS-AUTDR- Authorization Declined on reconciliation, TS-AUTFR- Authorization Fraud, TS-AUTFRR- Authorization Fraud on reconciliation, TS-AUTFRAM- Amt mismatch, TS-AUTFRCM - Currency mismatch, TS-AUTFRUT- Authorization fraud, TS-ABRTBNK- Aborted at bank's end. |
        | order\_type | Type of the order. | String. Different types of Orders: 1) OT-INV denotes “Invoice” 2) OT-ORD denotes “Orders” 3) OT-ORDSC denotes “Shopping Cart Orders” 4) OT-PPAY denotes” Phone Pay” 5) OT-SNIP denotes “SNIP orders” 6) OT-STO denotes “Storefront” 7) OT-ORDSI denotes “SI” 8) OT-BILL denotes “Bill\\A0Payments” 9) OT-LINK denotes “Link Pay” 10) OT-POS denotes “POS” 11) OT-TAPPAY denotes “Tap Pay” 12) OT-QR denotes “Offline QR” 13) OT-FORM denotes “Forms”. |
        | order\_ship\_address | Shipping Address for the order. | Possible value for address is Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315). |
        | order\_ship\_city | Shipping city name for the orders. | Possible value for city is Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | order\_ship\_country | Shipping country name for the orders. | Possible value for country is Alphanumeric with special characters (space)(30). |
        | order\_ship\_email | Shipping email ID for the notifications of the transaction. | Possible value for email id is Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | order\_ship\_name | Shipping Name of the Customer for the order. | Possible value for name is Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | order\_ship\_state | Shipping state for the order. | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | order\_ship\_tel | Telephone no for notifications of the transaction. | Numeric(10). |
        | order\_ship\_zip | Order shipping address' pin code for the order. | Possible value for zip is AlphaNumeric with special characters(hyphen and space) (15). |
        | order\_status | Status of the order. It can be single or multiple. | String. Possible values are: Aborted (transaction is cancelled by the User); Auto-Cancelled (transaction has not confirmed within 5 days hence auto cancelled by system); Auto-Reversed (two identical transactions for same order number, both were successful at bank's end but we got response for only one of them, then next day during reconciliation we mark one of the transaction as auto reversed); Awaited (transaction is processed from billing shipping page but no response is received); Cancelled (transaction is cancelled by merchant); Chargeback(); Invalid(Transaction sent to CCAvenue with Invalid parameters, hence could not be processed further); Fraud (we update this during recon, the amount is different at bank’s end and at CCAvenue due to tampering); Initiated (transaction just arrived on billing shipping page and not processed further); Refunded (Transaction is refunded.); Shipped (transaction is confirmed); Successful; System refund (Refunded by CCAvenue for various findings of reversals by CCAvenue); Unsuccessful (transaction is not successful due to ). |
        | order\_status\_date\_time | This is the latest date and time when the order status is modified. | DateTime in IST( yyyy-MM-dd HH:mm:ss.SSS) format. |
        | order\_TDS | Amount of TDS (tax deducted at source) for the Transaction. | Decimal(13,4). |
        | reference\_no | The unique CCAvenue reference no. allocated to the transaction. | Numeric(25). |
        | error\_desc | Reason if search criteria did not find the orders for the transactions. | String. Please refer to the below table for the failure message. |
        | error\_code | Error code for Failure reason. | String. Please refer to the below table for the failure message. |
        | page\_count | Total pages available based on no\_of\_records in the request. | Example: no\_of\_records sent in request was 100. total\_records matching the lookup criteria were 1000. page\_count will be 10 (total\_records / no\_of\_records) rounded to the ceiling. |
        | total\_records | Total no.of orders matching the lookup criteria |  |

        ## Example XML Response

        Sample Code

        Success Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?> <Pending_Orders_Result error_code="">
            <error_desc></error_desc>
            <page_count>1</page_count>
            <pending_Orders_List>
                <pending_Orders
                            days_left="3" 
                            order_TDS="0.0" 
                            order_amt="1.0" 
                            order_bank_mid="1234567890" 
                            order_bank_ref_no="289049" 
                            order_bank_response="Approved" 
                            order_bill_address="Room no 1101, near Railway station Ambad" 
                            order_bill_city="Indore" 
                            order_bill_country="India" 
                            order_bill_email="xxxxxx.xxxx@xxxxxx.xxxx" 
                            order_bill_name="Shashi" 
                            order_bill_state="MP" 
                            order_bill_tel="1234567890" 
                            order_bill_zip="425001" 
                            order_capt_amt="0.0" 
                            order_card_name="Amex" 
                            order_card_type="Credit Card" 
                            order_curr="INR" 
                            order_date_time="2015-04-13 10:59:05.517" 
                            order_discount="0.0" 
                            order_fee_flat="0.0" 
                            order_fee_perc="4.0" 
                            order_fee_perc_value="0.04" 
                            order_fraud_status="NA" 
                            order_gross_amt="1.0" 
                            order_gtw_id="PGT" 
                            order_ip="192.168.2.182" 
                            order_no="66885810" 
                            order_notes="order will be shipped" 
                            order_risk_mitigation="Y" 
                            order_ship_address="room no.701 near bus stand" 
                            order_ship_city="Hyderabad" 
                            order_ship_country="India" 
                            order_ship_name="Chaplin" 
                            order_ship_state="Andhra" 
                            order_ship_tel="1234567890" 
                            order_ship_zip="425001" 
                            order_status="Successful" 
                            order_status_date_time="2015-04-13 10:59:53.217" 
                            order_tax="0.0049" 
                            order_tran_status="TS-AUTC" 
                            order_type="OT-ORD" 
                            reference_no="204000136232"/>
        </pending_Orders_List>
        <total_records>1</total_records>
        </Pending_Orders_Result>
        ```

        Failure Response:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Pending_Orders_Result error_code="51313">
        <error_desc>No records found</error_desc>
        <page_count>1</page_count>
        <total_records>1</total_records>
        </Pending_Orders_Result>
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ## Example JSON Response

        Sample Code

        Success Response:

        json

        ```json
        {
        "pending_Orders":[{
        "reference_no":"204000136232",
        "order_no":"66885810",
        "order_type":"OT-ORD",
        "order_curr":"INR",
        "order_amt":1.0,
        "order_date_time":"2015-04-13 10:59:05.517",
        "order_bill_name":"Shashi",
        "order_bill_address":"Room no 1101, near Railway station Ambad",
        "order_bill_zip":"425001",
        "order_bill_city":"Indore",
        "order_bill_state":"MP",
        "order_bill_tel":"12345687890",
        "order_bill_email":"xxxxxxx@xxxxx.xxxx",
        "order_bill_country":"India",
        "order_ship_name":"Chaplin",
        "order_ship_address":"room no.701 near bus stand",
        "order_ship_country":"India",
        "order_ship_tel":"1234567890",
        "order_ship_city":"Hyderabad",
        "order_ship_state":"Andhra",
        "order_ship_zip":"425001",
        "order_notes":"order will be shipped",
        "order_ip":"192.168.2.182",
        "order_status":"Successful",
        "order_fraud_status":"NA",
        "order_status_date_time":"2015-04-13 10:59:53.217",
        "order_capt_amt":0.0,
        "order_card_type":"Credit Card",
        "order_card_name":"Amex",
        "order_bank_mid":"xxxxxxxxx",
        "order_fee_perc":4.0,
        "order_fee_perc_value":0.04,
        "order_fee_flat":0.0,
        "order_gross_amt":1.0,
        "order_discount":0.0,
        "order_tax":0.0049,
        "order_bank_ref_no":"xxxxxxxx",
        "order_gtw_id":"PGT",
        "order_bank_response":"Approved",
        "order_risk_mitigation":"Y",
        "days_left":"3",
        "order_tran_status":"TS-AUTC",
        "order_TDS":0.0,
        }],
        "page_count":1,
        "total_records":1,
        "error_desc":"",
        "error_code":""
        }
        ```

        Failure Response:

        json

        ```json
        {
        "page_count":1,
        "total_records":1,
        "error_desc":"No records found",
        "error_code":"51313"
        }
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ## Example STRING Request

        Success Response:

        Format:

        text

        ```text
        page_count|total_records|reference_no$order_no$order_status$order_trans_status$order_type$order_capt_amt$order_discount$order_fee_flat$order_fee_perc$order_fee_perc_value$order_gross_amt$order_tax$days_left$order_bank_mid$order_bank_ref_no$order_bank_response$order_bill_name$order_bill_email$order_bill_address$order_bill_city$order_bill_state$order_bill_country$order_bill_tel$order_bill_zip$order_card_name$order_card_type$order_curr$order_date_time$order_delivery_details$order_fraud_status$order_gtw_id$order_ip$order_misc_id$order_notes$order_risk_mitigation$order_ship_name$order_ship_email$order_ship_address$order_ship_city$order_ship_state$order_ship_country$order_ship_tel$order_ship_zip$order_status_date_time^|
        ```

        Example:

        text

        ```text
        1|1|204000136232$66885810$Successful$TS-AUTC$OT-ORD$0.0$0.0$0.0$4.0$0.04$1.0$0.0049$3$1234567890$289049$Approved$Shashi$xxxxxx.xx@xxxxxx.xxxx$Room no 1101, near Railway station Ambad$Indore$MP$India$1234567890$425001$Amex$Credit Card$INR$2015-04-13 10:59:05.517$$NA$PGT$192.168.2.182$$order will be shipped$Y$Chaplin$$room no.701 near bus stand$Hyderabad$Andhra$India$1236547892$425001$2015-04-13 10:59:53.217|
        ```

        Failure Response:

        Format:

        text

        ```text
        page_no|total_no_of_records|error_code|error_desc|
        ```

        Example:

        text

        ```text
        0|0|51313|No order found|
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

    # Update Merchant Params

        Update Merchant params API is used to add some extra parameter against Reference no if the same could not be done at the time of the transaction.

        ## Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | enc\_request (required) | AES encrypted request data |  |
        | access\_code (required) | Unique CCAvenue access code which is generated when merchant registered their IP address. You must send this with each request. |  |
        | request\_type (required) | API requests are accepted in XML, JSON or String formats. You need to specify the request type. | Possible value for request\_type is “XML” or “JSON” or “STRING”. |
        | response\_type (optional) | API returns XML, JSON or String responses. If left blank, the response will be in the same format as the request. | Possible value for response\_type is “XML” or “JSON” or “STRING”. |
        | command (required) | Command value specifies the API Calls. You must send this with each request. | Possible value for this API call is “updateMerchantParams” |
        | reference\_no (required) | Unique CCAvenue reference number for the transaction. | Numeric(25) |
        | param\_value (required) | Merchant can update one param value against unique reference no after placing order. | Alphanumeric with special characters(comma, hyphen, backslash and dot)(100) |
        | param\_value1 (optional) | Merchant can update one param value against unique reference no after placing order. | Alphanumeric with special characters(comma, hyphen, backslash and dot)(100) |
        | param\_value2 (optional) | Merchant can update one param value against unique reference no after placing order. | Alphanumeric with special characters(comma, hyphen, backslash and dot)(100) |
        | param\_value3 (optional) | Merchant can update one param value against unique reference no after placing order. | Alphanumeric with special characters(comma, hyphen, backslash and dot)(100). |
        | param\_value4 (optional) | Merchant can update one param value against unique reference no after placing order. | Alphanumeric with special characters(comma, hyphen, backslash and dot)(100) |

        ## Request Format:

        ### XML Format:

        Sample Code

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Merchant_Params_Query>
        <reference_no>203000093626</reference_no>
        <param_value>10</param_value>
        <param_value1>11</param_value1>
        <param_value2>22</param_value2>
        <param_value3>33</param_value3>
        <param_value4>44</param_value4>
        </Update_Merchant_Params_Query>
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ### JSON Format:

        Sample Code

        json

        ```json
        {
        "reference_no":"123654",
        "param_value":"10",
        "param_value1":"11",
        "param_value2":"22",
        "param_value3":"33",
        "param_value4":"44"
        }
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ### String Format:

        Format:

        text

        ```text
        Reference_no|Param_value1|Param_value2|Param_value3|Param_value4|Param_value5|
        ```

        Example:

        text

        ```text
        123456|10|11|22|33|44|
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | status | This states whether the call was successful or not. If value of this parameter is “1” then you need not decrypt the enc\_response as it will contain plain error message. | Value “0” denotes that the API call is successful. Value “1” denotes that the API call has failed. If enc\_response is plain text, it represents the error message. |
        | enc\_response | AES encrypted response containing format as per response\_type |  |
        | error\_desc | Failure reason if API call does not update/add param value. | Please refer to the below table for the failure message. |
        | error\_code | Error code for Failure reason. | String. Please refer to the below table for the failure message. |
        | update\_status | Update status contains merchant parameter if add/update process was successful or not. | “0” means update merchant parameter value call was successful. “1” means update merchant param value call was not successful. |

        ## Response Format:

        ### XML Format:

        Success:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Merchant_Param_Result update_status="0" error_code="" error_desc=""/>
        ```

        Failure:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Merchant_Param_Result error_desc="Invalid reference no" update_status="1" error_code="51321"/>
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ### JSON Format:

        Success:

        json

        ```json
        {
            "update_status":0,
            "error_desc":"",
            "error_code":""
        }
        ```

        Failure:

        json

        ```json
        {
            "error_desc":"Invalid reference no",
            "error_code":"51321",
            "update_status":1
        }
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ### String Format:

        Success Format:

        text

        ```text
        update_status|
        ```

        Example:

        text

        ```text
        0|
        ```

        Failure Format:

        text

        ```text
        update_status|error_code|error_desc|
        ```

        Example:

        text

        ```text
        1|51321|Invalid reference no .|
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

    # Update Billing Details

        Update billing details API call is used to update customer billing information against an order.

        ## Request Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | enc\_request (required) | AES encrypted request data |  |
        | access\_code (required) | Unique CCAvenue access code which is generated when merchant registered their IP address. You must send this with each request. |  |
        | request\_type (required) | API requests are accepted in XML, JSON or STRING formats. You need to specify the request type. | Possible value for request\_type is “XML” or “JSON” or “STRING”. |
        | response\_type (optional) | API returns XML, JSON or String responses. If left blank, the response will be in the same format as the request. | Possible value for response\_type is “XML” or “JSON” or “STRING”. |
        | command (required) | Command value specifies the API Calls. You must send this with each request. | Possible value for this API call is “updateBillingDetails” |
        | reference\_no (required) | Unique CCAvenue reference no. for the transaction. | Numeric value(25). |
        | bill\_name (required) | Billing name for the order | Alphanumeric with special characters (space, hyphen, apostrophe, underscore, dot)(60). |
        | bill\_email (required) | Billing email id for order | Alphanumeric with special characters (hyphen, underscore, dot, @)(70). |
        | bill\_address (required)421=Unsupported version for the API call. | Billing address for order | Alphanumeric with special characters (space, hyphen, comma, ampersand(&), hash(#), circular brackets and dot)(315) |
        | bill\_city (required) | Billing city for order | Alphanumeric with special characters (space, comma, hyphen and dot)(30). |
        | bill\_state (required) | Billing state for order | Alphanumeric with special characters (hyphen, dot and space)(30). |
        | bill\_country (required) | Billing country for order | Alphanumeric with special characters (space)(30). |
        | bill\_zip (required) | Billing zip for order | AlphaNumeric with special characters(hyphen and space) (15). |

        ## Request format:

        ### XML Format:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Billing_Details_Query>
        <reference_no>123456</reference_no>
        <bill_name>bill name</bill_name>
        <bill_email>szgfs.sdgf@sfdg.com </bill_email>
        <bill_address>santacruz(west)</bill_address>
        <bill_city>mumbai</bill_city>
        <bill_state>maharashtra</bill_state>
        <bill_country>india</bill_country>
        <bill_zip>400000</bill_zip>
        </Update_Billing_Details_Query>
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ### JSON Format:

        json

        ```json
        {
            "reference_no":"123456",
            "bill421=Unsupported version for the API call._name":"bill name",
            "bill_email":"zgfs.sdgf@sfdg.com",
            "bill_address":"santacruz(west)",
            "bill_city":"mumbai",
            "bill_state":"maharashtra",
            "bill_country":"india",
            "bill_zip":"400000"   
        }
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ### String Format:

        Format:

        text

        ```text
        Reference No|Billing Name|Bill Email ID|Bill Address|Bill City|Bill State|Bill Country|Bill Zip|
        ```

        Example:

        text

        ```text
        123456|bill name|xxx@xxx.com|santacruz(west)|mumbai|maharashtra|india|400054|
        ```

        Note: You will have to encrypt the above request and store in the "enc\_request" parameter before sending it to CCAvenue. Kindly refer to the encryption section.

        ## Response Parameters

        | Name | Description | Note |
        | --- | --- | --- |
        | status421=Unsupported version for the API call. | This states whether the call was successful or not. If value of this parameter is “1” then you need not decrypt the enc\_response as it will contain plain error message. | Value “0” denotes that the API call is successful. Value “1” denotes that the API call has failed. If enc\_response is plain text, it represents the error message. |
        | enc\_response | AES encrypted response containing format as per response\_type |  |
        | error\_desc | Failure reason if billing details are not updated successfully. | String. Please refer to the below table for the failure message. |
        | error\_code | Error code for Failure reason. | String. Please refer to the below table for the failure message. |
        | update\_status | Update\_status denotes whether Billing information have been updated successfully or not. | “0” means update billing details was successful. “1” means billing details are not successfully updated. |

        ## Response Format:

        ### XML Format:

        Success:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Param_Result update_status="0" error_desc="" error_code=""/>
        ```

        Failure:

        xml

        ```xml
        <?xml version="1.0" encoding="UTF-8" standalone="yes"?>
        <Update_Bill_Details_Result update_status="" error_desc="Invalid Reg Id or Reference No" />
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ### JSON Format:

        Success:

        json

        ```json
        {
            "update_status":0,
            "error_desc":"",
            "error_code":""
        }
        ```

        Failure:

        json

        ```json
        {
            "error_desc":"Invalid Reg Id or Reference No",
            "error_code":"51321",
            "update_status":1
        }
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        ### String Format:

        Success Format:

        text

        ```text
        update_status|
        ```

        Example:

        text

        ```text
        0|
        ```

        Failure Format:

        text

        ```text
        update_status|error_code|error_desc|
        ```

        Example:

        text

        ```text
        1|51321|Invalid Reg Id or Reference No|
        ```

        Note: You will have to decrypt the above response from "enc\_response" parameter. Kindly refer to the decryption section.

        text

        ```text
        421=Unsupported version for the API call.
        ```

# Error Codes

    Error Message when API status is `1`

        | Error Code | Short Description | Long Description | Message |
        | --- | --- | --- | --- |
        | `51401` | Invalid Parameter | request\_type: Invalid Parameter | Ensure that request\_type parameter is not invalid. |
        | `51402` | Missing Parameter | Command: Required parameter missing | Ensure that Command parameter is not blank. |
        | `51403` | Missing Parameter | access\_code: Required Parameter is Missing | Ensure that access\_code parameter is not blank. |
        | `51404` | Invalid Parameter | Request Type: Invalid Parameter | Ensure that Request Type parameter value is only XML/JSON/STRING. |
        | `51405` | Invalid Parameter | Response Type: Invalid Parameter | Ensure that Response Type parameter value is only XML/JSON/STRING. |
        | `51406` | Invalid Parameter | Command: Invalid Parameter | Ensure that Command parameter is not invalid. |
        | `51407` | access\_code: Invalid Parameter | API Call: Invalid Parameter | You are not allowed to perform this operation. |
        | `51408` | Missing Parameter | enc\_request: Required parameter missing | Ensure that enc\_request parameter is not blank. |
        | `51410` | Invalid Parameters | Command Name: Invalid Parameter | Ensure that the command name is not invalid. Please refer API document for valid command. |
        | `51411` | Invalid Parameters | JSON request format: Invalid Parameter | Ensure that the JSON request format is not invalid. Please refer API document for the API call. |
        | `51412` | Invalid Parameter | XML request format: Invalid Parameter | Ensure that XML request format is not invalid. Please refer API document for the API call. |
        | `51413` | Invalid Parameter | STRING request format: Invalid Parameter | Ensure that STRING request format is not invalid. Please refer API document for the API call. |
        | `51421` | Invalid Parameter | API Call: Invalid Parameter | Please use only supported version for the API call. |
        | `-1` | Invalid Parameter | enc\_request: Invalid Request | Ensure that Request parameter is not invalid. |

    ## Failure Reason for API Call

        | Error Code | Long Description | Message | Applicable To |
        | --- | --- | --- | --- |
        | `51001` | Reference Number: Required parameter missing | Ensure that the Reference Number parameter is not blank. | Confirm, Cancel, Refund, Status, UpdateBillingDetails, UpdateMerchantParams, InvoiceLookUp |
        | `51002` | Reference Number: Invalid Parameter | Ensure that the Reference Number parameter is numeric. | Confirm, Cancel, Refund, Status, OrderLookUp, UpdateBillingDetails, UpdateMerchantParams, InvoiceLookUp, GetPendingOrders |
        | `51003` | Reference Number: Invalid Parameter | Ensure that the Reference Number parameter does not exceed 25 characters. | Confirm, Cancel, Refund, Status, OrderLookUp, UpdateBillingDetails, UpdateMerchantParams, InvoiceLookUp, GetPendingOrders |
        | `51004` | Order Number: Invalid Parameter | Ensure that reference no/order number is mandatory. | Status |
        | `51006` | Start/From Date: Required parameter missing | Ensure that start/from date is provided. | OrderLookUp |
        | `51007` | Start/From Date: Invalid Parameter | Ensure that Start/From date is in dd-mm-yyyy format. | OrderLookUp |
        | `51008` | End/To Date: Invalid Parameter | Ensure that End/To date is in dd-mm-yyyy format. | OrderLookUp |
        | `51009` | Mobile number: Required parameter missing | Ensure that Mobile Number parameter is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice, Order Lookup |
        | `51010` | Mobile number: Invalid Parameter | Ensure that Mobile number parameter contains 10 digits. | InvoiceLookUp, GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice, GetPendingOrders, OrderLookUp |
        | `51011` | Email id: Required parameter missing | Ensure that Email id is provided. | GenerateQuickInvoice, GenerateRecurringInvoice |
        | `51012` | Email id: Invalid Parameter | Ensure that only letters, numbers, hyphen, dot, one @ and underscore are provided for Email id parameter. | InvoiceLookUp, GenerateQuickInvoice, GenerateRecurringInvoice, GetPendingOrders, Order Lookup |
        | `51013` | Email id: Invalid Parameter | Ensure that Email id parameter does not exceed 70 characters. | InvoiceLookUp, GenerateQuickInvoice, GenerateRecurringInvoice, GetPendingOrders |
        | `51014` | Amount: Required parameter missing | Ensure that Amount parameter is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice, Refund Order |
        | `51015` | Amount: Invalid Parameter | Ensure that the Amount parameter is in Decimal. | Confirm, Cancel, Refund, OrderLookUp, InvoiceLookUp |
        | `51017` | Order Number: Invalid Parameter | Ensure only letters, numbers, hyphen and underscore are provided for Order Number parameter. | Status, GetPendingOrders, OrderLookUp |
        | `51018` | Order Number: Invalid Parameter | Ensure that Order Number parameter does not exceed 30 characters. | Status, GetPendingOrders, OrderLookUp |
        | `51020` | Minimum amount: Invalid Parameter | Ensure that Minimum amount parameter has Decimal value. | Order Lookup |
        | `51022` | Maximum amount: Invalid Parameter | Ensure that Maximum amount parameter has Decimal value. | Order Lookup |
        | `51024` | Page Number: Invalid Parameter | Ensure that Page Number parameter is greater than zero. | Order Lookup |
        | `51026` | Invoice id: Invalid Parameter | Ensure that invoice id parameter is numeric. | Invoice Lookup |
        | `51028` | Invoice Number: Invalid Parameter | Ensure that only letters, number hyphen and underscore are provided for invoice Number parameter. | Order Lookup |
        | `51029` | Invoice Number: Invalid Parameter | Ensure that Invoice number parameter does not exceed 30 characters. | Order Lookup |
        | `51031` | Invoice type: Invalid Parameter | Ensure that values for invoice type parameter are TASK/ITEM. | InvoiceLookUp |
        | `51032` | Currency: Required parameter missing | Ensure that Currency parameter is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51033` | Currency: Invalid Parameter | Ensure that Currency is assigned to the merchant. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51034` | Valid for: Required parameter missing | Ensure that 'valid for' parameter is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51035` | Valid for: Invalid Parameter | Ensure that 'valid for' parameter is Numeric. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51036` | Valid for: Invalid Parameter | Ensure that 'valid for' parameter does not exceed 4 digits. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51037` | Valid type: Required parameter missing | Ensure that valid type parameter value is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51038` | Valid type: Invalid Parameter | Ensure that values for valid type parameter are days/hours/minutes. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51039` | Bill delivery type: Required parameter missing | Ensure that bill delivery Type parameter is not blank. | GenerateQuickInvoice |
        | `51040` | Bill delivery type: Invalid Parameter | Ensure that value for bill delivery type parameter is SMS/EMAIL. | GenerateQuickInvoice |
        | `51041` | Name: Required parameter missing | Ensure that Name parameter is not blank. | UpdateBillingDetails, GenerateQuickInvoice, GenerateRecurringInvoice |
        | `51042` | Name: Invalid Parameter | Ensure that only letters, space, dot, underscore, hyphen and single apostrophe are provided for Name parameter. | UpdateBillingDetails, GenerateQuickInvoice, GenerateRecurringInvoice, PendingOrder |
        | `51043` | Name: Invalid Parameter | Ensure that Name parameter does not exceed 60 characters. | UpdateBillingDetails, GenerateQuickInvoice, GenerateRecurringInvoice, PendingOrder |
        | `51044` | Address: Required parameter missing | Ensure that Address parameter is not blank. | UpdateBillingDetails |
        | `51045` | Address: Invalid Parameter | Ensure only letters, numbers, space, hyphen, comma, ampersand, hash, circular brackets and dot are provided for Address parameter. | UpdateBillingDetails |
        | `51046` | Address: Invalid Parameter | Ensure that Address parameter does not exceed 315 characters. | UpdateBillingDetails |
        | `51047` | City: Required parameter missing | Ensure that City parameter is not blank. | Missing Mer\_charge\_reference\_number, UpdateBillingDetails |
        | `51048` | City: Invalid Parameter | Ensure that only numbers, letters, space, comma, hyphen and dot are provided for City parameter. | UpdateBillingDetails |
        | `51049` | City: Invalid Parameter | Ensure that City parameter does not exceed 30 characters. | UpdateBillingDetails |
        | `51050` | Zip/PIN code: Required parameter missing | Ensure that Zip/PIN code parameter is not blank. | UpdateBillingDetails |
        | `51051` | Zip/PIN code: Invalid Parameter | Ensure that only letters, numbers, hyphen and space are provided for Zip/PIN code parameter. | UpdateBillingDetails |
        | `51052` | Zip/PIN code: Invalid Parameter | Ensure that Zip/PIN code parameter does not exceed 15 characters. | UpdateBillingDetails |
        | `51053` | State: Required parameter missing | Ensure that State parameter is not blank. | UpdateBillingDetails |
        | `51054` | State: Invalid Parameter | Ensure that only letters, hyphen, dot and space are provided for State parameter. | UpdateBillingDetails |
        | `51055` | State: Invalid Parameter | Ensure that State parameter does not exceed 30 characters. | UpdateBillingDetails |
        | `51056` | Country: Required parameter missing | Ensure that Country Code parameter is not blank. | UpdateBillingDetails |
        | `51057` | Country: Invalid Parameter | Ensure that only letters and space are allowed for Country Code parameter. | UpdateBillingDetails |
        | `51058` | Country: Invalid Parameter | Ensure that Country Code parameter does not exceed 30 characters. | UpdateBillingDetails |
        | `51059` | Customer id: Required parameter missing | Ensure that Customer id parameter is not blank. | GetCustomerPaymentOptions, DeleteCustomerPaymentOption, DeleteCustomer, Customer payment option |
        | `51060` | Customer id: Invalid Parameter | Ensure that only numerical values are provided for Customer id parameter. | GetCustomerPaymentOptions, DeleteCustomerPaymentOption, DeleteCustomer |
        | `51061` | Customer id: Invalid Parameter | Ensure that Customer id parameter does not exceed 25 digits. | GetCustomerPaymentOptions, DeleteCustomerPaymentOption, DeleteCustomer, Customer payment option |
        | `51062` | Customer card id: Required parameter missing | Ensure that Customer card id is not blank. | DeleteCustomerPaymentOption |
        | `51063` | Customer card id: Invalid Parameter | Ensure that only numbers are provided for Customer card id parameter. | DeleteCustomerPaymentOption |
        | `51064` | Customer card id: Invalid Parameter | Ensure that Customer card id parameter does not exceed 25 characters. | DeleteCustomerPaymentOption |
        | `51065` | Parameter value: Required parameter missing | Ensure that Merchant Params value is not blank. | UpdateMerchantParams |
        | `51066` | Parameter value: Invalid Parameter | Ensure that Alphanumeric, comma, hyphen, backslash and dot are provided for parameters Value. | UpdateMerchantParams |
        | `51067` | Parameter value: Invalid Parameter | Ensure that Parameter value does not exceed 100 characters. | UpdateMerchantParams |
        | `51068` | Bill id: Required parameter missing | Ensure that Bill id parameter is not blank. | UpdateInvoiceReferenceNumber, Updatemerchantreferencenumber |
        | `51069` | Bill id: Invalid Parameter | Ensure that Bill id parameter is a numeric value greater than zero. | UpdateInvoiceReferenceNumber, InvoiceLookUp, Updatemerchantreferencenumber |
        | `51070` | Bill id: Invalid Parameter | Ensure that Bill id parameter does not exceed 25 characters. | UpdateInvoiceReferenceNumber, InvoiceLookUp |
        | `51071` | Merchant reference number: Required parameter missing | Ensure that merchant Reference Number is not blank. | UpdateInvoiceReferenceNumber |
        | `51072` | Merchant reference number: Invalid Parameter | Ensure that only letters, numbers, hyphen and underscore are provided for merchant Reference number parameter. | UpdateInvoiceReferenceNumber, GenerateRecurringInvoice, GenerateInvoice |
        | `51073` | Merchant reference number: Invalid Parameter | Ensure that Merchant reference number parameter does not exceed 25 characters. | UpdateInvoiceReferenceNumber, GenerateRecurringInvoice, GenerateInvoice |
        | `51074` | SMS Content: Required parameter missing | Ensure that SMS Content parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51075` | SMS Content: Required parameter missing | Ensure that 'Basic place holders in SMS Content' parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51076` | SMS Content: Invalid Parameter | Ensure that SMS content parameter does not exceed 500 Characters. | GenerateRecurringInvoice, GenerateInvoice |
        | `51077` | Email subject: Required parameter missing | Ensure that Email Subject parameter is not blank. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51078` | Email subject: Invalid Parameter | Ensure that Email subject parameter contains only letters, numbers, hyphens, dot, space and underscores. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51079` | Email subject: Invalid Parameter | Ensure that Email subject parameter does not exceed 100 characters. | GenerateQuickInvoice, GenerateRecurringInvoice, GenerateInvoice |
        | `51080` | Email Description: Invalid Parameter | Ensure that Email Description parameter contains only letters, numbers, hyphen, dot, circular brackets, space, comma, underscore, hash and ampersand. | GenerateRecurringInvoice, GenerateInvoice |
        | `51081` | Email Description: Invalid Parameter | Ensure that Email Description parameter does not exceed 100 characters. | Generate QuickInvoice |
        | `51082` | File List: Invalid Parameter | Ensure that only one file is attached in quick invoice. | GenerateQuickInvoice |
        | `51083` | File List: Invalid Parameter | Ensure that Attached File extension is (doc, jpg, jpeg, docx, pdf, png). | GenerateQuickInvoice |
        | `51084` | File List: Invalid Parameter | Ensure that File size does not exceed 1MB. | GenerateQuickInvoice |
        | `51085` | Refund Reference Number: Required parameter missing | Ensure that 'refund reference no' parameter is not blank. | Refund |
        | `51086` | Refund Reference Number: Invalid Parameter | Ensure that Only letters and numbers are provided for refund Reference Number parameter. | Refund |
        | `51087` | Refund reference number: Invalid Parameter | Ensure that Refund reference number parameter does not exceed 100 characters. | Refund orders |
        | `51088` | Frequency: Required parameter missing | Ensure that Frequency parameter is not blank. | GenerateRecurringInvoice |
        | `51089` | Frequency: Invalid Parameter | Ensure that values for frequency parameter are only Daily/Monthly/Quarterly/Yearly. | GenerateRecurringInvoice |
        | `51118` | Occurrences: Required parameter missing | Ensure that Occurrences parameter is not blank. | GenerateRecurringInvoice |
        | `51119` | Occurrences: Invalid Parameter | Ensure that Occurrences parameter is Numeric. | GenerateRecurringInvoice |
        | `51120` | Occurrences: Invalid Parameter | Ensure that Occurrences parameter does not exceed 3 digits. | GenerateRecurringInvoice |
        | `51121` | Occurrences: Invalid Parameter | Adding occurrences is only applicable for recurring invoice. | GenerateRecurringInvoice |
        | `51122` | Task name: Required parameter missing | Ensure that task name parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51123` | Task name: Invalid Parameter | Ensure that only letters, space, underscore and hyphen are provided for task name parameter. | GenerateRecurringInvoice, GenerateInvoice |
        | `51124` | Task name: Invalid Parameter | Ensure that Task name parameter does not exceed 30 Characters. | GenerateRecurringInvoice, GenerateInvoice |
        | `51125` | Task Note: Required parameter missing | Ensure that Task Note parameter is not blank. | Generate Invoice |
        | `51126` | Task note: Invalid Parameter | Ensure that Task note parameter contains only letters, numbers, hyphen, dot, circular brackets, space, comma, underscore, hash and ampersand. | GenerateRecurringInvoice, GenerateInvoice |
        | `51127` | Task Note: Invalid Parameter | Ensure that Task Note parameter does not exceed 60 characters. | Generate Invoice |
        | `51128` | Task rate: Required parameter missing | Ensure that task rate parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51129` | Task rate: Invalid Parameter | Ensure that task rate parameter is decimal. | GenerateRecurringInvoice, GenerateInvoice |
        | `51130` | Task hour: Required parameter missing | Ensure that task hour parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51131` | Task hour: Invalid Parameter | Ensure that task hour parameter is numeric. | GenerateRecurringInvoice, GenerateInvoice |
        | `51132` | Task hour: Invalid Parameter | Ensure that task hour value does not exceed 4 digits. | GenerateRecurringInvoice, GenerateInvoice |
        | `51133` | Item name: Required parameter missing | Ensure that item name parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51134` | Item name: Invalid Parameter | Ensure that only letters, space, underscore and hyphen are provided for item name parameter. | GenerateRecurringInvoice, GenerateInvoice |
        | `51135` | Item name: Invalid Parameter | Ensure that Item name parameter does not exceed 30 characters. | GenerateRecurringInvoice, GenerateInvoice |
        | `51136` | Item description: Required parameter missing | Ensure that item description parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51137` | Item description: Invalid Parameter | Ensure that Item description parameter contains only letters, numbers, hyphen, dot, circular brackets, space, comma, underscore, hash and ampersand. | GenerateRecurringInvoice, GenerateInvoice |
        | `51138` | Item description: Invalid Parameter | Ensure that Item description parameter does not exceed 60 characters. | GenerateRecurringInvoice, GenerateInvoice |
        | `51139` | Item Quantity: Required parameter missing | Ensure that the item quantity parameter is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51140` | Item Quantity: Invalid Parameter | Ensure that the Item Quantity parameter is numeric. | GenerateRecurringInvoice, GenerateInvoice |
        | `51141` | Item Quantity: Invalid Parameter | Ensure that the Item Quantity parameter does not exceed 3 digits. | GenerateRecurringInvoice, GenerateInvoice |
        | `51142` | Unit cost: Required parameter missing | Ensure that unit cost of item is not blank. | GenerateRecurringInvoice, GenerateInvoice |
        | `51143` | Unit cost: Invalid Parameter | Ensure that the Unit cost of item parameter is a Decimal Number. | GenerateRecurringInvoice, GenerateInvoice |
        | `51144` | Terms and Conditions: Invalid Parameter | Ensure that Only letters, numbers, hyphen, dot, circular brackets and ampersand are provided for Terms and Condition parameter. | GenerateRecurringInvoice, GenerateInvoice |
        | `51145` | Terms and Conditions: Invalid Parameter | Ensure that Terms and Conditions parameter does not exceed 500 characters. | GenerateRecurringInvoice, GenerateInvoice |
        | `51146` | Due date: Invalid Parameter | Ensure that Due date parameter is Numeric. | GenerateRecurringInvoice, GenerateInvoice |
        | `51147` | Due date: Invalid Parameter | Ensure that Due date parameter does not exceed 3 digits. | GenerateRecurringInvoice, GenerateInvoice |
        | `51148` | Due Date: Invalid Parameter | Ensure that due date parameter is provided when late payment fees is present. | GenerateRecurringInvoice, GenerateInvoice |
        | `51149` | Late payment fees: Invalid Parameter | Ensure that late payment fees parameter is provided when due date is present. | GenerateRecurringInvoice, GenerateInvoice |
        | `51150` | Discount\_if\_paid\_within\_due\_date: Invalid Parameter | Ensure that value for discount\_if\_paid\_within\_due\_date parameter is not greater than due date. | GenerateRecurringInvoice, GenerateInvoice |
        | `51151` | Discount Type: Required parameter missing | Ensure that discount type parameter is provided when discount value is present. | GenerateRecurringInvoice, GenerateInvoice |
        | `51152` | Discount value: Required parameter missing | Ensure that discount value parameter is provided when discount type is present. | GenerateRecurringInvoice, GenerateInvoice |
        | `51153` | Late payment fees type: Required parameter missing | Ensure that late payment fees type parameter is provided when late payment fees is present. | GenerateRecurringInvoice, GenerateInvoice |
        | `51155` | Discount value: Invalid Parameter | Ensure that Discount value parameter ranges are: For percentage (1.00 - 99.99); For flat (1.00 - 99999.99). | GenerateRecurringInvoice, GenerateInvoice |
        | `51156` | Late payment fees: Invalid Parameter | Ensure that Late payment fees parameter ranges are: For percentage (1.00 - 99.99); For flat (1.00 - 99999.99). | GenerateRecurringInvoice, GenerateInvoice |
        | `51157` | Tax List: Invalid Parameter | Ensure that there are not more than two taxes for one task. | GenerateRecurringInvoice, GenerateInvoice |
        | `51158` | Item/Task List: Invalid Parameter | Ensure that at least one task/item is added. | GenerateRecurringInvoice, GenerateInvoice |
        | `51159` | Task List: Invalid Parameter | Ensure that the same task is not added twice. | GenerateRecurringInvoice, GenerateInvoice |
        | `51160` | Item List: Invalid Parameter | Ensure that the same item is not added twice. | GenerateRecurringInvoice, GenerateInvoice |
        | `51161` | Discount value: Invalid Parameter | Ensure that the Discount value parameter is Decimal. | GenerateRecurringInvoice, GenerateInvoice |
        | `51162` | Late payment fees: Invalid Parameter | Ensure that Late payment fees parameter is Decimal. | GenerateRecurringInvoice, GenerateInvoice |
        | `51163` | Discount type: Invalid Parameter | Ensure that values for Discount type parameter are Perc/Flat. | GenerateRecurringInvoice, GenerateInvoice |
        | `51164` | Late payment fees type: Invalid Parameter | Ensure that values for Late payment fees type parameter are Perc/Flat. | GenerateRecurringInvoice, GenerateInvoice |
        | `51165` | Discount if paid within due date: Invalid Parameter | Ensure that Discount if paid within due date parameter is Numeric. | GenerateRecurringInvoice, GenerateInvoice |
        | `51166` | Discount if paid within due date: Invalid Parameter | Ensure that Discount if paid within due date parameter does not exceed 3 digits. | GenerateRecurringInvoice, GenerateInvoice |
        | `51167` | Tax List: Invalid Parameter | Ensure that the same tax is not added twice for the same task/item. | GenerateRecurringInvoice, GenerateInvoice |
        | `51168` | Tax List: Invalid Parameter | Ensure that there are not more than two taxes applicable for one item. | Generate Invoice |
        | `51169` | Due Date: Invalid Parameter | Ensure that Due date cannot be greater than valid for parameter. | Generate Recurring Invoice |
        | `51205` | Order List: Invalid Parameter | Please provide at least one order list. | Confirm, Cancel |
        | `51206` | Order List: Invalid Parameter | Ensure that Reference Number parameter is not repeated. | Confirm, Cancel |
        | `51207` | Start Date: Invalid Parameter | Ensure that Start date is greater than or equal to current date. | GenerateRecurringInvoice |
        | `51208` | Start Date: Invalid Parameter | Adding start date is only applicable for recurring invoice. | GenerateRecurringInvoice |
        | `51209` | Frequency: Invalid Parameter | Adding frequency is only applicable for recurring invoice. | GenerateRecurringInvoice, GenerateInvoice |
        | `51301` | Order List: Invalid Parameter | 'Order is already confirmed:' +  + '#.' | Confirm |
        | `51302` | Order List: Invalid Parameter | 'Order is already Cancelled:' +  + '#.' | Cancel |
        | `51303` | Order List: Invalid Parameter | 'Invalid order status:' +  + '#.' | Confirm, Cancel |
        | `51304` | Order List: Invalid Parameter | 'Invalid order/tracking id :' +  + '#.' | Confirm, Cancel |
        | `51305` | Order List: Invalid Parameter | 'Pending Risk Mitigation:' +  + '#.' | Confirm, Cancel |
        | `51306` | Order List: Invalid Parameter | 'Order Amt Mismatch:' +  + '#.' | Confirm, Cancel |
        | `51307` | Order List: Invalid Parameter | Pending risk mitigation. | Confirm, Cancel |
        | `51308` | Order List: Invalid Parameter | No record was found for the given criteria. | Refund, Status, OrderLookUp |
        | `51309` | Reference number: Invalid Parameter | Ensure that merchant reference number parameter is not repeated. | Refund |
        | `51310` | Reference number: Invalid Parameter | Refund not allowed. | Refund |
        | `51311` | Reference number: Invalid Parameter | Multiple refunds are not allowed for the gateway. | Refund |
        | `51312` | Reference number: Invalid Parameter | Cannot initiate refund request for the given order. | Refund |
        | `51313` | Order List: Invalid Parameter | No records found. | Order Status, OrderLookUp |
        | `51314` | Reference number: Invalid Parameter | Refund limit exceeds. | Refund |
        | `51315` | Reference number: Invalid Parameter | This is a disputed order. You can refund only up to `{Currency}{Refund amount limit}`. | Refund (Currency and Refund amount limit are dynamically appended) |
        | `51316` | Reference number: Invalid Parameter | Ensure that Reg Id or Reference No parameters are not invalid. | UpdateBillingDetails |
        | `51317` | Reference number: Invalid Parameter | Record already updated or Invalid Order Status. | UpdateBillingDetails |
        | `51318` | Reference number: Invalid Parameter | Ensure that Order Status OR Order Type parameters are not invalid. | UpdateBillingDetails |
        | `51319` | bill\_id: Invalid Parameter | Ensure that bill\_id parameter is not invalid. | UpdateInvoiceReferenceNumber |
        | `51320` | Bill Merchant Reference No: Invalid Parameter | Bill Merchant Reference No parameter already exists. | UpdateInvoiceReferenceNumber |
        | `51321` | Reg ID: Invalid Parameter | Ensure that Reg ID parameter is not invalid. | UpdateMerchantParams |
        | `51322` | Reference number: Invalid Parameter | Ensure that reference number parameter is not invalid. | UpdateMerchantParams |
        | `51323` | Tax List: Invalid Parameter | Ensure that Tax is configured for the merchant. | GenerateInvoice, GenerateRecurringInvoice |
        | `51324` | Tax List: Invalid Parameter | Ensure that Tax value matches with the given Tax name. | GenerateInvoice, GenerateRecurringInvoice |
        | `51325` | Customer id: Required parameter missing | Ensure that Customer parameter is present. | DeleteCustomer |
        | `51326` | Currency: Invalid Parameter | Ensure that Currency is assigned to the merchant. | GenerateInvoice, GenerateRecurringInvoice, GenerateQuickInvoice |
        | `51327` | Customer id: Invalid Parameter | Ensure that the merchant is not invalid. | OrderLookup, Delete Customer |
        | `51328` | Reference number: Invalid Parameter | Multiple refunds not allowed. | RefundOrder |
        | `51336` | Customer Card Id: Invalid Parameter | Card Not Present. | DeleteCustomer payment option |

