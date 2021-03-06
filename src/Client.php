<?php 

namespace Tokenly\XChainClient;

use Exception;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Tokenly\CurrencyLib\Quantity;
use Tokenly\HmacAuth\Generator;
use Tokenly\XChainClient\Exception\XChainException;

/**
* XChain Client
*/
class Client
{
    
    function __construct($xchain_url, $api_token, $api_secret_key)
    {
        $this->xchain_url     = $xchain_url;
        $this->api_token      = $api_token;
        $this->api_secret_key = $api_secret_key;
    }

    /**
     * creates a new payment address
     * @return array An array with an (string) id and (string) address
     */
    public function newPaymentAddress() {
        $result = $this->newAPIRequest('POST', '/addresses', []);
        return $result;
    }

    /**
     * creates a new payment address that XChain will track
     * But XChain does not hold the private key for this address
     * @param  string  $address   bitcoin/counterparty address
     * @return array An array with an (string) id and the (string) address
     */
    public function newUnmanagedPaymentAddress($address) {
        $result = $this->newAPIRequest('POST', '/unmanaged/addresses', [
            'address' => $address,
        ]);
        return $result;
    }


    /**
     * get the payment address details
     * @param  string $uuid id of the paymehnt address
     * @return array  array of payment address details
     */
    public function getPaymentAddress($uuid) {
        $result = $this->newAPIRequest('GET', '/addresses/'.$uuid);
        return $result;
    }

    /**
     * destroys the payment address
     * @param  string $uuid id of the paymehnt address
     * @return array  an empty array
     */
    public function destroyPaymentAddress($uuid) {
        $result = $this->newAPIRequest('DELETE', '/addresses/'.$uuid);
        return $result;
    }


    /**
     * creates a new payment address that XChain will track
     * But XChain does not hold the private key for this address
     * @param  string  $wallet_name      The wallet name shown in Pockets
     * @param  string  $multsig_typ      2of2 or 2of3
     * @param  string  $webhook_endpoint webhook callback URL to receive the joined event callback
     * @param  string  $copayer_name     The name of the cosigning application
     * @return array An array with an (string) id and an (string) invitationCode for use in Pockets to join this address
     */
    public function newMultisigPaymentAddress($wallet_name, $multisig_type, $webhook_endpoint=null, $copayer_name=null) {
        if (!in_array($multisig_type, ['2of2','2of3'])) { throw new Exception("Invalid multisig type", 1); }

        $post_vars = [
            'name'         => $wallet_name,
            'multisigType' => $multisig_type,
        ];
        if ($webhook_endpoint !== null) { $post_vars['webhookEndpoint'] = $webhook_endpoint; }
        if ($copayer_name !== null)     { $post_vars['copayerName']     = $copayer_name; }
        $result = $this->newAPIRequest('POST', '/multisig/addresses', $post_vars);
        return $result;
    }

    /**
     * sends confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  string $fee_rate           A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @param  string $message            message
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @return array                      An array with the send information, including `id`
     */
    public function sendFromMultisigAddress($payment_address_id, $destination, $quantity, $asset, $fee_rate='medium', $message=null, $dust_size=null, $request_id=null) {
        $body = [
            'destination' => $destination,
            'quantity'    => $quantity,
            'asset'       => $asset,
            'feeRate'     => $fee_rate,
        ];
        if ($dust_size !== null)  { $body['dust_size'] = $dust_size; }
        if ($request_id !== null) { $body['requestId'] = $request_id; }
        if ($message !== null)    { $body['message']   = $message; }

        $result = $this->newAPIRequest('POST', '/multisig/sends/'.$payment_address_id, $body);
        return $result;
    }

    /**
     * destroys an address monitor from the DB
     * @param string $send_id  the id from sendFromMultisigAddress
     * @return array empty array
     */   
    public function destroyMultisigSend($send_id)
    {
        $result = $this->newAPIRequest('DELETE', '/multisig/sends/'.$send_id, array());
        return $result; 
    }

    /**
     * retrieve information and status of a multisig send
     * For convenience, the payment proposal status is returned in $result['copayStatus']
     * @param  string $send_id  the id from sendFromMultisigAddress
     * @return array            An array with the send information.  Includes `copayTransaction` which contains the copay status information.
     */   
    public function getMultisigSend($send_id)
    {
        $result = $this->newAPIRequest('GET', '/multisig/sends/'.$send_id, array());
        $result['copayStatus'] = 
            (isset($result['copayTransaction']) AND $result['copayTransaction']['status']) 
            ? $result['copayTransaction']['status'] 
            : null;
        return $result; 
    }

    /**
     * issue a new token from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  float  $quantity           quantity to issue
     * @param  string $asset              asset name to issue
     * @param  bool   $divisible          Whether the asset is divisible or not
     * @param  string $description        description attached to the issuance
     * @param  string $fee_rate           A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @param  string $request_id         a unique id for this request
     * @param  string $fee_satoshis       An exact fee to use in satoshis
     * @return array                      An array with the issuance information, including `id`
     */
    public function createIssuanceFromMultisigAddress($payment_address_id, $quantity, $asset, $divisible, $description='', $fee_rate='medium', $request_id=null, $fee_satoshis=null) {
        $body = [
            'quantity'  => $quantity,
            'asset'     => $asset,
            'divisible' => $divisible,
        ];
        if ($fee_rate !== null)      { $body['feeRate']     = $fee_rate; }
        if ($fee_satoshis !== null)  { $body['feeSat']      = $fee_satoshis; }
        if ($request_id !== null)    { $body['requestId']   = $request_id; }
        if ($description !== null)   { $body['description'] = $description; }

        $result = $this->newAPIRequest('POST', '/multisig/issuances/'.$payment_address_id, $body);
        return $result;
    }

    /**
     * issue a new token from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  float  $quantity           quantity to issue
     * @param  string $asset              asset name to issue
     * @param  bool   $divisible          Whether the asset is divisible or not
     * @param  string $description        description attached to the issuance
     * @param  string $fee_satoshis       An exact fee to use in satoshis
     * @param  string $request_id         a unique id for this request
     * @return array                      An array with the issuance information, including `id`
     */
    public function createIssuanceFromMultisigAddressWithExactFee($payment_address_id, $quantity, $asset, $divisible, $description, $fee_satoshis, $request_id=null) {
        return $this->createIssuanceFromMultisigAddress($payment_address_id, $quantity, $asset, $divisible, $description, $_fee_rate=null, $request_id, $fee_satoshis);
    }

    /**
     * monitor a new address
     * @param  string  $address          bitcoin/counterparty address
     * @param  string  $webhook_endpoint webhook callback URL
     * @param  string  $monitor_type     send or receive
     * @param  boolean $active           active
     * @return array                     The new monitor object
     */
    public function newAddressMonitor($address, $webhook_endpoint, $monitor_type='receive', $active=true) {
        $body = [
            'address'         => $address,
            'webhookEndpoint' => $webhook_endpoint,
            'monitorType'     => $monitor_type,
            'active'          => $active,
        ];
        $result = $this->newAPIRequest('POST', '/monitors', $body);
        return $result;
    }
    
    /**
     * switches a monitor between active and inactive states
     * @param string $id                the uuid of the address monitor
     * @param boolean $active           active
     * @return array                    monitor object
     * */
    public function updateAddressMonitorActiveState($id, $active=true) {
        $body = [
            'active'          => $active,
        ];
        $result = $this->newAPIRequest('PATCH', '/monitors/'.$id, $body);
        return $result;
    }
    
    /**
     * get details about an address monitor
     * @param string $id                the uuid of the address monitor
     * @return array                    monitor object
     * */   
    public function getAddressMonitor($id)
    {
        $result = $this->newAPIRequest('GET', '/monitors/'.$id, array());
        return $result; 
    }
    
    /**
     * destroys an address monitor from the DB
     * @param string $id                the uuid of the address monitor
     * @return null
     * */   
    public function destroyAddressMonitor($id)
    {
        $result = $this->newAPIRequest('DELETE', '/monitors/'.$id, array());
        return $result; 
    }

    /**
     * monitor a new event
     * @param  string  $webhook_endpoint  webhook callback URL
     * @param  string  $event_type        an event type (block,issuance,broadcast)
     * @return array                      The new monitor object
     */
    public function newEventMonitor($webhook_endpoint, $event_type) {
        $body = [
            'monitorType'     => $event_type,
            'webhookEndpoint' => $webhook_endpoint,
        ];
        $result = $this->newAPIRequest('POST', '/event_monitors', $body);
        return $result;
    }
    
    /**
     * Updates an event monitor
     * @param string $id                the uuid of the event monitor
     * @param boolean $active           active
     * @return array                    monitor object
     * */
    public function updateEventMonitor($id, $webhook_endpoint, $event_type) {
        $body = [
            'monitorType'     => $event_type,
            'webhookEndpoint' => $webhook_endpoint,
        ];
        $result = $this->newAPIRequest('PATCH', '/event_monitors/'.$id, $body);
        return $result;
    }
    
    /**
     * get details about an event monitor
     * @param string $id                the uuid of the event monitor
     * @return array                    monitor object
     * */   
    public function getEventMonitor($id)
    {
        $result = $this->newAPIRequest('GET', '/event_monitors/'.$id, array());
        return $result; 
    }
    
    /**
     * destroys an event monitor from the DB
     * @param string $id                the uuid of the event monitor
     * @return null
     * */   
    public function destroyEventMonitor($id)
    {
        $result = $this->newAPIRequest('DELETE', '/event_monitors/'.$id, array());
        return $result; 
    }

    /**
     * sends confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  string $fee_rate           A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @return array                      An array with the send information, including `txid`
     */

    public function sendWithFeeRate($payment_address_id, $destination, $quantity, $asset, $fee_rate='medium', $request_id=null) {
        return $this->sendFromAccount($payment_address_id, $destination, $quantity, $asset, 'default', $_unconfirmed=true, $_fee=null, $_dust_size=null, $request_id, $_custom_inputs=null, $fee_rate);
    }

    /**
     * sends confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     *
     * ** Deprecated ** - Use sendWithFeeRate instead
     *
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  float  $fee                bitcoin fee
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @param  array  $custom_inputs      custom list of utxos to use to build this transaction, format [{txid: id, n: 0}*]
     * @return array                      An array with the send information, including `txid`
     */
    public function send($payment_address_id, $destination, $quantity, $asset, $fee=null, $dust_size=null, $request_id=null, $custom_inputs=false) {
        return $this->sendFromAccount($payment_address_id, $destination, $quantity, $asset, 'default', true, $fee, $dust_size, $request_id, $custom_inputs);
    }

    /**
     * sends only confirmed funds from the given payment address
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  float  $fee                bitcoin fee
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @return array An array with the send information, including `txid`
     */
    public function sendConfirmed($payment_address_id, $destination, $quantity, $asset, $fee=null, $dust_size=null, $request_id=null) {
        return $this->sendFromAccount($payment_address_id, $destination, $quantity, $asset, 'default', false, $fee, $dust_size, $request_id);
    }

    /**
     * sends confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  string $account            an account name to send from
     * @param  bool   $unconfirmed        allow unconfirmed funds to be sent
     * @param  float  $fee                bitcoin fee (deprecated - use fee_rate instead)
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @param  array  $custom_inputs      custom list of utxos to use to build this transaction, format [{txid: id, n: 0}*]
     * @param  string $fee_rate           A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @return array                      An array with the send information, including `txid`
     */
    public function sendFromAccount($payment_address_id, $destination, $quantity, $asset, $account='default', $unconfirmed=false, $fee=null, $dust_size=null, $request_id=null, $custom_inputs=false, $fee_rate=null) {
        $body = [
            'destination'   => $destination,
            'quantity'      => $quantity,
            'asset'         => $asset,
            'sweep'         => false,
            'unconfirmed'   => $unconfirmed,
            'account'       => $account,
            'utxo_override' => $custom_inputs,
        ];
        if ($fee !== null)        { $body['fee']       = $fee; }
        if ($dust_size !== null)  { $body['dust_size'] = $dust_size; }
        if ($request_id !== null) { $body['requestId'] = $request_id; }
        if ($fee_rate !== null)   { $body['feeRate']   = $fee_rate; }

        $result = $this->newAPIRequest('POST', '/sends/'.$payment_address_id, $body);
        return $result;
    }


    /**
     * sends confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_id address uuid
     * @param  string $destination        destination bitcoin address
     * @param  float  $quantity           quantity to send
     * @param  string $asset              asset name to send
     * @param  string $account            an account name to send from
     * @param  bool   $unconfirmed        allow unconfirmed funds to be sent
     * @param  float  $fee                bitcoin fee
     * @param  float  $dust_size          bitcoin transaction dust size for counterparty transactions
     * @param  string $request_id         a unique id for this request
     * @param  array  $custom_inputs      custom list of utxos to use to build this transaction, format [{txid: id, n: 0}*]
     * @return array                      An array with the send information, including `txid`
     */
    public function createUnsignedSend($payment_address_id, $destination, $quantity, $asset, $account='default', $unconfirmed=false, $fee=null, $dust_size=null, $request_id=null, $custom_inputs=false) {
        $body = [
            'destination'   => $destination,
            'quantity'      => $quantity,
            'asset'         => $asset,
            'unconfirmed'   => $unconfirmed,
            'account'       => $account,
            'utxo_override' => $custom_inputs,
        ];
        if ($fee !== null)        { $body['fee']       = $fee; }
        if ($dust_size !== null)  { $body['dust_size'] = $dust_size; }
        if ($request_id !== null) { $body['requestId'] = $request_id; }

        $result = $this->newAPIRequest('POST', '/unsigned/sends/'.$payment_address_id, $body);
        return $result;
    }

    /**
     * sends confirmed and unconfirmed BTC from the given payment address to multiple destinations
     *
     * @param  string $payment_address_id address uuid
     * @param  array  $destinations       destination bitcoin addresses with values. In the form of [['address' => '1XXXXXXX1111', 'amount' => 0.001], ['address' => '1XXXXXXX2222', 'amount' => 0.005]]
     * @param  string $account            an account name to send from
     * @param  bool   $unconfirmed        allow unconfirmed funds to be sent
     * @param  float  $fee                bitcoin fee
     * @param  string $request_id         a unique id for this request
     * @param  string $fee_rate           A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @return array                      An array with the send information, including `txid`
     */
    public function sendBTCToMultipleDestinations($payment_address_id, $destinations, $account='default', $unconfirmed=false, $fee=null, $request_id=null, $fee_rate=null) {
        $body = [
            'destinations' => $destinations,
            'sweep'        => false,
            'unconfirmed'  => $unconfirmed,
            'account'      => $account,
        ];
        if ($fee !== null)        { $body['fee']       = $fee; }
        if ($request_id !== null) { $body['requestId'] = $request_id; }
        if ($fee_rate !== null)   { $body['feeRate']   = $fee_rate; }

        $result = $this->newAPIRequest('POST', '/multisends/'.$payment_address_id, $body);
        return $result;
    }

    /**
     * sends all assets and all BTC to a destination address
     * @return array the send details
     */
    public function sweepAllAssets($payment_address_id, $destination, $fee=null, $dust_size=null, $request_id=null, $fee_rate=null) {
        $body = [
            'destination' => $destination,
            'quantity'    => null,
            'asset'       => 'ALLASSETS',
            'sweep'       => true,
        ];
        if ($fee !== null)        { $body['fee']       = $fee; }
        if ($dust_size !== null)  { $body['dust_size'] = $dust_size; }
        if ($request_id !== null) { $body['requestId'] = $request_id; }
        if ($fee_rate !== null)   { $body['feeRate']   = $fee_rate; }

        $result = $this->newAPIRequest('POST', '/sends/'.$payment_address_id, $body);
        return $result;
    }

    /**
     * Gets the current asset balances for a bitcoin address
     * For balances of payment addresses, please see the getAccountBalances method.
     * @param  string $address bitcoin address
     * @param  boolean $as_satoshis if true, return balances insatoshis
     * @return array an array like ['ASSET' => value, 'ASSET2' => value]
     */
    public function getBalances($address, $as_satoshis=false) {
        $result = $this->newAPIRequest('GET', '/balances/'.$address);
        $key = ($as_satoshis ? 'balancesSat' : 'balances');
        return $result[$key];
    }
    
    /**
     * gets info for a particular asset
     * @param string $asset counterparty asset
     * @return array
     * */
    public function getAsset($asset)
    {
        $result = $this->newAPIRequest('GET', '/assets/'.$asset);
        return $result;
    }

    /**
     * gets info for a particular asset
     * @param array $asset an array of counterparty asset
     * @return array a numbered array with asset information
     * */
    public function getAssets($assets)
    {
        $result = $this->newAPIRequest('GET', '/assets', ['assets' => implode(',', $assets)]);
        return $result;
    }

    ////////////////////////////////////////////////////////////////////////
    // Acounts

    /**
     * Creates a new account for the payment address
     * @param  string $payment_address_uuid payment address id
     * @param  string $account_name         a name of the account
     * @param  array $meta_data             optional meta data stored along with this account
     * @return array                        the new account
     */
    public function createAccount($payment_address_uuid, $account_name, $meta_data=null) {
        $body = [
            'addressId' => $payment_address_uuid,
            'name'      => $account_name,
        ];
        if ($meta_data !== null) { $body['meta'] = $meta_data; }

        $result = $this->newAPIRequest('POST', '/accounts', $body);
        return $result;
    }

    /**
     * Updates an existing account
     * @param  string $account_uuid account id
     * @param  string $account_name a name of the account
     * @param  array $meta_data     optional meta data stored along with this account
     * @return array                the updated account
     */
    public function updateAccount($account_uuid, $account_name=null, $meta_data=null) {
        $body = [];

        if ($account_name !== null) { $body['name'] = $account_name; }
        if ($meta_data !== null) { $body['meta'] = $meta_data; }

        $result = $this->newAPIRequest('PATCH', '/accounts', $body);
        return $result;
    }



    /**
     * Fetch existing accounts
     * @param  string $payment_address_uuid the address id
     * @param  boolean $active              Set to false to get the inactive accounts
     * @return array                        a numbered array of all accounts
     */
    public function getAccounts($payment_address_uuid, $active=true) {
        $result = $this->newAPIRequest('GET', '/accounts/'.$payment_address_uuid.'?active='.($active ? '1' : '0'));
        return $result;
    }

    /**
     * Fetch an existing account by ID
     * @param  string $account_uuid account id
     * @param  boolean $active              Set to false to get the inactive accounts
     * @return array                        the account data
     */
    public function getAccount($account_uuid) {
        $result = $this->newAPIRequest('GET', '/account/'.$account_uuid);
        return $result;
    }


    /**
     * Fetch existing accounts with balances.
     * This is the fastest and preferred way of obtaining balances for payment addresses managed by XChain.
     * If type is not specified, the result looks like this
     * {
     *     "unconfirmed": {
     *         "BTC": 4
     *     }
     *     "confirmed": {
     *         "BTC": 10,
     *         "TOKENLY": 4
     *     },
     *     "sending": [],
     * }
     * If type is specified, the result looks like this
     * {
     *     "BTC": 10,
     *     "TOKENLY": 4
     * }
     * @param  string $payment_address_uuid the address id
     * @param  string $account_name         An account name
     * @param  string $type                 Only show balances of a specific type (unconfirmed, confirmed, sending)
     * @return array                        An array of all active accounts with balances
     */
    public function getAccountBalances($payment_address_uuid, $account_name, $type=null) {
        $params = ['name' => $account_name];
        if ($type !== null) { $params['type'] = $type; }

        $result = $this->newAPIRequest('GET', '/accounts/balances/'.$payment_address_uuid, $params);
        if ($result) { return $result[0]['balances']; }

        return $result;
    }


    /**
     * Fetch existing accounts with balances
     * An example result might look like this
     * [
     *     {
     *         "id": "3c411819-ffb8-40a9-82f9-6805c95567c9",
     *         "name": "myNewCarSavings",
     *         "active": true,
     *         "meta": {
     *             "foo": "bar"
     *         },
     *         "balances": {
     *             "confirmed": {
     *                 "BTC": 10,
     *                 "TOKENLY": 4
     *             },
     *             "unconfirmed": {
     *                 "BTC": 4
     *             },
     *             "sending": []
     *         }
     *     },
     * ]
     * @param  string $payment_address_uuid the address id
     * @return array                        An array of all active accounts with balances
     */
    public function getAllAccountsWithBalances($payment_address_uuid) {
        $params = [];

        $result = $this->newAPIRequest('GET', '/accounts/balances/'.$payment_address_uuid, $params);
        return $result;
    }


    /**
     * @return array                        An array of all active accounts with balances
     */


    /**
     * Transfer funds from one account to another
     * @param  string $payment_address_uuid the address id
     * @param  string $from                 The name of the account to transfer from
     * @param  string $to                   Account name to transfer to.  This account will be created if it does not exist.
     * @param  float  $quantity             Quantity of the asset to transfer
     * @param  string $asset                Asset name to transfer
     * @param  string $txid                 To transfer unconfirmed funds, specify a transaction id
     * @return boolean                      true on success, false if funds are not available
     */
    public function transfer($payment_address_uuid, $from, $to, $quantity, $asset, $txid=null) {
        $body = [
            'from'     => $from,
            'to'       => $to,
            'quantity' => $quantity,
            'asset'    => $asset,
        ];

        if ($txid !== null) { $body['txid'] = $txid; }

        try {
            $result = $this->newAPIRequest('POST', '/accounts/transfer/'.$payment_address_uuid, $body);
        } catch (XChainException $e) {
            // handle an INSUFFICIENT_FUNDS error
            if ($e->getErrorName() == 'ERR_INSUFFICIENT_FUNDS') {
                return false;
            }
        }
        return true;
    }

    /**
     * Transfers all funds from one account to another that are tagged with a transaction ID
     * @param  string $payment_address_uuid the address id
     * @param  string $from                 The name of the account to transfer from
     * @param  string $to                   Account name to transfer to.  This account will be created if it does not exist.
     * @param  string $txid                 A transaction id
     * @return array                        An empty array
     */
    public function transferAllByTransactionID($payment_address_uuid, $from, $to, $txid) {
        $body = [
            'from'     => $from,
            'to'       => $to,
            'txid'     => $txid,
        ];

        $result = $this->newAPIRequest('POST', '/accounts/transfer/'.$payment_address_uuid, $body);
        return $result;
    }

    /**
     * Transfer all funds from one account to another
     * @param  string $payment_address_uuid the address id
     * @param  string $from                 The name of the account to transfer from
     * @param  string $to                   Account name to transfer to.  This account will be created if it does not exist.
     * @return boolean                      true on success
     */
    public function closeAccount($payment_address_uuid, $from, $to) {
        $body = [
            'from'     => $from,
            'to'       => $to,
            'close'    => true,
        ];

        $result = $this->newAPIRequest('POST', '/accounts/transfer/'.$payment_address_uuid, $body);
        return true;
    }

    /**
     * check the number of primed UTXOs a given address has
     * An example result might look like this
     * {
     *     "primedCount": 1,
     *     "totalCount": 2,
     *     "utxos": [
     *         {
     *             "txid": "1111111111111111111111111111111111111111111111111111111111110004",
     *             "n": "0",
     *             "amount": 15430,
     *             "type": "confirmed",
     *             "green": true
     *         },
     *         {
     *             "txid": "1111111111111111111111111111111111111111111111111111111111110004",
     *             "n": "1",
     *             "amount": 3.0e-5,
     *             "type": "unconfirmed",
     *             "green": true
     *         }
     *     ]
     * }
     * @param  string $payment_address_uuid the address id
     * @param  float $utxo_size the size of the primed UTXOs to check for
     * @return array The API call result
     */
    public function checkPrimedUTXOs($payment_address_uuid, $utxo_size) {
        $vars = [
            'size' => $utxo_size,
        ];

        $result = $this->newAPIRequest('GET', '/primes/'.$payment_address_uuid, $vars);
        return $result;
    }

    /**
     * Ensures a payment address has multiple UTXOs of a certain size
     * An example result might look like this
     * {
     *     "primedCount": 1,
     *     "totalCount": 2,
     *     "txid": "999999992e2981dd792c7a1b484e9d6a5a8a65355d121b8f014848421fe1b164",
     *     "primed": true
     * }
     * @param  string  $payment_address_uuid the address id
     * @param  float   $utxo_size            the size of the primed UTXOs to create
     * @param  integer $desired_count        the number of primed UTXOs to create
     * @param  float   $fee                  bitcoin fee
     * @param  string  $fee_rate             A fee rate to use. Accepts a pre-defined setting ("low","lowmed","medium","medhigh","high"), a number of blocks ("6 blocks"), or an exact number of satohis per byte ("75")
     * @return array The API call result
     */
    public function primeUTXOsWithFeeRate($payment_address_uuid, $utxo_size, $desired_count, $fee_rate='medium') {
        $body = [
            'size'    => $utxo_size,
            'count'   => $desired_count,
            'feeRate' => $fee_rate,
        ];

        $result = $this->newAPIRequest('POST', '/primes/'.$payment_address_uuid, $body);
        return $result;
    }

    /**
     * Ensures a payment address has multiple UTXOs of a certain size
     * An example result might look like this
     * {
     *     "primedCount": 1,
     *     "totalCount": 2,
     *     "txid": "999999992e2981dd792c7a1b484e9d6a5a8a65355d121b8f014848421fe1b164",
     *     "primed": true
     * }
     * 
     * ** Deprecated ** - Use primeUTXOsWithFeeRate instead
     * 
     * @param  string $payment_address_uuid the address id
     * @param  float $utxo_size the size of the primed UTXOs to create
     * @param  integer $desired_count the number of primed UTXOs to create
     * @param  float $fee bitcoin fee
     * @return array The API call result
     */
    public function primeUTXOs($payment_address_uuid, $utxo_size, $desired_count, $fee=null) {
        $body = [
            'size'  => $utxo_size,
            'count' => $desired_count,
        ];
        if ($fee !== null) { $body['fee'] = $fee; }

        $result = $this->newAPIRequest('POST', '/primes/'.$payment_address_uuid, $body);
        return $result;
    }
    
    /*
    * Checks to see if a string is a valid bitcoin address
    * @param string $address the BTC address
    * @return array the API call result (result: boolean, is_mine: boolean)
    */
    public function validateAddress($address)
    {
        $result = $this->newAPIRequest('GET', '/validate/'.$address);
        return $result;
    }
    
    
    /*
    * Verifies a message signed with a bitcoin address
    * @param string $address signers bitcoin address
    * @param string $sig the cryptographic signature
    * @param string $message the message to verify against
    * @return array the API call result (result: boolean)
    */
    public function verifyMessage($address, $sig, $message)
    {
        $body = ['sig' => $sig, 'message' => $message];
        $result = $this->newAPIRequest('GET', '/message/verify/'.$address, $body);
        return $result;
    }
    
    /*
    * Signs a message using a bitcoin address
    * @param string $address bitcoin address or uuid
    * @param string $message the message
    * @return array the API call result (result: string)
    */
    public function signMessage($address, $message)
    {
        $body = ['message' => $message];
        $result = $this->newAPIRequest('POST', '/message/sign/'.$address, $body);
        return $result;
    }



    /**
     * estimates the fee for sending confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  mixed $priority              priority to estimate.  Either low, med, high or a number.  If using a number, the number is the number of satoshis per byte.
     * @param  string $payment_address_id   address uuid
     * @param  string $destination          destination bitcoin address
     * @param  float  $quantity             quantity to send
     * @param  string $asset                asset name to send
     * @param  float  $dust_size            bitcoin transaction dust size for counterparty transactions
     * @return Tokenly\CurrencyLib\Quantity The fee as a Quantity object.
     */
    public function estimateFee($priority, $payment_address_id, $destination, $quantity, $asset, $dust_size=null) {
        return $this->estimateFeeFromAccount($priority, $payment_address_id, $destination, $quantity, $asset, 'default', true, $dust_size);
    }

    /**
     * estimates the fee for sending funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  mixed $priority              priority to estimate.  Either low, med, high or a number.  If using a number, the number is the number of satoshis per byte.
     * @param  string $payment_address_id   address uuid
     * @param  string $destination          destination bitcoin address
     * @param  float  $quantity             quantity to send
     * @param  string $asset                asset name to send
     * @param  string $account              an account name to send from
     * @param  bool   $unconfirmed          allow unconfirmed funds to be sent
     * @param  float  $dust_size            bitcoin transaction dust size for counterparty transactions
     * @return Tokenly\CurrencyLib\Quantity The fee as a Quantity object.
     */
    public function estimateFeeFromAccount($priority, $payment_address_id, $destination, $quantity, $asset, $account='default', $unconfirmed=false, $dust_size=null) {
        $body = [
            'destination' => $destination,
            'quantity'    => $quantity,
            'asset'       => $asset,
            'sweep'       => false,
            'unconfirmed' => $unconfirmed,
            'account'     => $account,
        ];
        if ($dust_size !== null)  { $body['dust_size'] = $dust_size; }

        $result = $this->newAPIRequest('POST', '/estimatefee/'.$payment_address_id, $body);
        if (isset($result['fees'][$priority])) {
            return new Quantity($result['fees'][$priority.'Sat']);
        }

        return new Quantity(intval($priority) * $result['size']);
    }

    /**
     * returns fee per byte estimates in the form of:
     * {
     *     "low": 5,
     *     "medlow": 84,
     *     "medium": 118,
     *     "medhigh": 151,
     *     "high": 201
     * }
     * @return Array Fee rates in satoshis per byte
     * 
     */
    public function getFeeRates() {
        $result = $this->newAPIRequest('GET', '/feerates');
        return $result;
    }

    /**
     * estimates the fee for sending confirmed and unconfirmed funds from the given payment address
     * confirmed funds are sent first if they are available
     * @param  string $payment_address_uuid address uuid
     * @param  int    $utxos_to_consolidate  The maximum number of UTXOs to consolidate unto a single UTXO.  Can be up to 150.
     * @param  mixed  $priority              Fee priority to estimate.  Either low, med, high or a number.  If using a number, the number is the number of satoshis per byte.
     * @return array Response data like ['before_utxos_count' => 20, 'after_utxos_count'  => 10, 'cleaned_up' => true, 'txid' => $txid,]
     */
    public function cleanupUTXOs($payment_address_uuid, $utxos_to_consolidate, $priority=null) {
        $body = [
            'max_utxos' => $utxos_to_consolidate,
        ];
        if ($priority !== null)  { $body['priority'] = $priority; }

        $result = $this->newAPIRequest('POST', '/cleanup/'.$payment_address_uuid, $body);
        return $result;
    }


    ////////////////////////////////////////////////////////////////////////

    protected function newAPIRequest($method, $path, $data=[]) {
        $api_path = '/api/v1'.$path;

        $client = new GuzzleClient();

        if ($data AND ($method == 'POST' OR $method == 'PATCH')) {
            $body = \GuzzleHttp\Psr7\stream_for(json_encode($data));
            $headers = ['Content-Type' => 'application/json'];
            $request = new \GuzzleHttp\Psr7\Request($method, $this->xchain_url.$api_path, $headers, $body);
        } else if ($method == 'GET') {
            $request = new \GuzzleHttp\Psr7\Request($method, $this->xchain_url.$api_path);
            $request = \GuzzleHttp\Psr7\modify_request($request, ['query' => http_build_query($data, null, '&', PHP_QUERY_RFC3986)]);
        } else {
            $request = new \GuzzleHttp\Psr7\Request($method, $this->xchain_url.$api_path);
        }

        // add auth
        $request = $this->getAuthenticationGenerator()->addSignatureToGuzzle6Request($request, $this->api_token, $this->api_secret_key);
        
        // send request
        try {
            $response = $client->send($request);
        } catch (RequestException $e) {
            if ($response = $e->getResponse()) {
                // interpret the response and error message
                $code = $response->getStatusCode();
                try {
                    $json = json_decode($response->getBody(), true);
                } catch (Exception $parse_json_exception) {
                    // could not parse json
                    $json = null;
                }
                if ($json and isset($json['message'])) {
                    // throw an XChainException with the errorName
                    if (isset($json['errorName'])) {
                        $xchain_exception = new XChainException($json['message'], $code);
                        $xchain_exception->setErrorName($json['errorName']);
                        throw $xchain_exception;
                    }

                    if (isset($json['errors']) AND is_array($json['errors'])) {
                        // errors exception
                        $errors_text = implode(" ", $json['errors']);
                        if ($errors_text != $json['message']) {
                            $errors_text = $json['message'].' '.$errors_text;
                        }
                        throw new Exception($errors_text, $code);
                    }

                    // generic exception
                    throw new Exception($json['message'], $code);
                }
            }

            // if no response, then just throw the original exception
            throw $e;
        }

        $code = $response->getStatusCode();
        if ($code == 204) {
            // empty content
            return [];
        }

        $json = json_decode($response->getBody(), true);
        if (!is_array($json)) { throw new Exception("Unexpected response: ".$response->getBody(), 1); }

        return $json;
    }

    protected function getAuthenticationGenerator() {
        $generator = new Generator();
        return $generator;
    }

}
