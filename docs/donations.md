## General

The donations API, located in the "OSF" entity, supports the following
features:

- Fetching contact details given a contact hash
- Fetching contract/membership data and payment-related information given a membership ID and contact hash
- Creating or matching contacts
- Creating one-off donations
- Creating recurring donations (memberships/contracts)
- Creating webshop orders
- Retrieving a list of Web-related campaigns
- Retrieving a list of available webshop products

A Standard use case would be that the API-using application sends the contact data to Civi to check, if the contact already exists there. If so, an existing contact_id is returned, otherwise a new contact_id is created and returned. Whith this information the application is able to send a contribution or a membership to Civi and connect it with the before communicated contact_id. If the membership or contribution is connected with an order of an item, the application will also create an activity from type `webshop_order`.

## Endpoints

### Campaigns `(OSF.getcampaigns)`

#### Description
The `OSF.getcampaigns` endpoint allows clients to retrieve a list of all the campaigns in Civi which the OSF is allowed to see.

#### Return Value

From the return values you shall use for input fields:

- `external_id` for `campaign`
- `id` for `campaign_id`

---

### Option Value `(OSF.getproducts)`

#### Description

The `OSF.getproducts` endpoint allows clients to retrieve a list of all products for the OSF available.

#### Return Value

Returns an array of known products. The following properties are the most relevant:

 * `label` contains the human-readable product name that should be used in the user interface.
 * `value` is the identifier of the product that should be used as the value for the `order_type` parameter of the `OSF.order` endpoint.
 * `weight` can be used to determine the order in which options are rendered in the user interface (lowest number first).

---

### Get Contact `(OSF.getcontact)`

#### Description
Receive contact-related information given a contact hash.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `hash`*          | String  |            | Contact hash                        |
<small>**\* mandatory field**</small>

#### Return Value

| Field (required) | Type    | Description                         |
| ---------------  | ------- | ----------------------------------- |
| `contact_id`*    | Integer | CiviCRM contact type |
| `first_name`     | String  | |
| `last_name`      | String  | |
| `prefix`         | String  | AT: One of `Herr`, `Frau`, `Familie` |
| `gender`         | String  | One of `Male`, `Female`, `Other`
| `birth_date`     | Date    | Format: `YYYY-MM-DD` |
| `bpk`            | String  | Austrian tax office identifier for `contact_type` = `Individual` |
| `email`          | String  | |
| `phone`          | String  | |
| `street_address` | String  | Street name and house number separated by one space |
| `postal_code`    | String  | |
| `city`           | String  | |
| `country`        | String  | Country code according to ISO 3166-1 alpha-2 |
<small>**\* mandatory field**</small>

#### Error codes

| Error Code        | Description                                              |
| ----------------- | -------------------------------------------------------- |
| `unknown_hash`    | The provided contact hash does not exist or was deleted. |

---

### Get Contract `(OSF.getcontract)`

#### Description
Receive membership and payment-related information given a contact hash and contract ID.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `hash`*          | String  |            | Contact hash                        |
| `contract_id`*   | Integer |            | Contract/Membership ID              |
<small>**\* mandatory field**</small>

#### Return Value

| Field (required)            | Type    | Description                         |
| --------------------------  | ------- | ----------------------------------- |
| `frequency`*                | Integer | Number of debits per year, e.g. 12 for monthly |
| `amount`*                   | Float   | Amount for each individual debit (i.e. monthly amount for monthly contracts). Format: `1000.00` |
| `annual_amount`*            | Float   | Annual debit amount (frequency * amount) |
| `cycle_day`*                | Integer | Day of month on which debits are performed |
| `currency`*                 | String  | ISO 4217 currency code |
| `membership_type`*          | String  | AT: One of `Förderer`, `Könige der Wälder`, `Flottenpatenschaft`, `Landwirtschaft`, `Baumpatenschaft`, `arctic defender`, `Guardian of the Ocean`, `Walpatenschaft`, `Atom-Eingreiftrupp`, `Greenpeace for me` |
| `status`*                   | String  | One of `Current`, `Paused`, `Cancelled` |
| `payment_instrument`*       | String  | One of: `RCUR`, `Credit Card`, `Sofortüberweisung`, `EPS`. **Note:** RCUR is SEPA |
| `payment_service_provider`* | String  | One of: `civicrm`, `adyen`. `civicrm` is used for SEPA/RCUR |
| `payment_label`             | String  | Human-readable label for the used payment instrument which can be displayed to the user and is intended to help them recognize it. For SEPA this is the masked IBAN, e.g.: `AT71 **** **** **** 8032`. **Note:** Only SEPA/RCUR is supported currently.
| `payment_details`*          | Array   | Elements depend on `payment_instrument` and `payment_service_provider` |
| *for payment_instrument=\*, payment_service_provider=adyen:* |
| ↳ `shopper_reference`*      | String  | Adyen Shopper Reference |
| ↳ `merchant_account`*       | String  | Adyen Merchant Account, e.g. `GreenpeaceAT` |
| *for payment_instrument=RCUR, payment_service_provider=civicrm:* |
| ↳ `iban`*                   | String  | IBAN |
<small>**\* mandatory field**</small>

#### Error codes

| Error Code                             | Description                                              |
| -------------------------------------- | -------------------------------------------------------- |
| `unknown_hash`                         | The provided contact hash does not exist or was deleted. |
| `unknown_contract`                     | The contract does not exist or does not belong to this contact. |
| `payment_instrument_unsupported`       | The contract has a payment instrument that is not supported by this API. |
| `payment_service_provider_unsupported` | The contract has a payment service provider that is not supported by this API. |
| `payment_method_invalid`               | The contract has an invalid payment method. |

---

### Contacts `(OSF.submit)`

#### Description
The `OSF.submit` endpoint allows clients to transparently get or create contacts
in CiviCRM based on their contact details. CiviCRM matches the submitted data
to existing contacts based on rules defined within CiviCRM and either returns
a new contact or the matching existing contact.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_type`   | String  | `Individual` | CiviCRM contact type |
| `first_name`[^1] | String  | | |
| `last_name`[^1]  | String  | | |
| `prefix`         | String  | | Values available: `Herr`, `Frau`, `Familie`. <br /> **Note:** These values may be language- and instance-specific. It is preferred to set `gender_id` and leave this field empty whenever possible. |
| `gender_id`      | String  | | Values available: `Male`, `Female`, `Other`. <br />**Note:** These values are case-sensitive. |
| `birth_date`[^1] | Date    | | Format: `YYYY-MM-DD` |
| `bpk`[^1]        | String  | | Austrian tax office identifier for `contact_type` = `Individual` |
| `email`[^1]      | String  | | Need to be valid format `%@%.%` otherwise it is garbaged by API |
| `phone`          | String  | | Is normalized by normalize extension |
| `iban`[^1]       | String  | | Only used as a matching criteria |
| `street_address`[^1] | String | | Street name and house number separated by one space |
| `postal_code`[^1] | String | | |
| `city`           | String  | | |
| `country`        | String  | | Country code according to ISO 3166-1 alpha-2 |
| `newsletter`     | Boolean | `0` | Whether this contact opted-in to the email newsletter |

[^1]:
    At least *one* of the following (combinations of) fields needs to be present:

    - `bpk`
    - `first_name` and `email`
    - `last_name` and `email`
    - `iban` and `birth_date`
    - `first_name` and `last_name` and `postal_code` and `street_address`

#### Return Value

Returns an array where the key `id` contains the CiviCRM Contact ID.

---

### Contribution `(OSF.donation)`

#### Description
The `OSF.donation` endpoint allows clients to transfer the donation data into CiviCRM. CiviCRM matches the submitted data
to existing contacts based on the `contact_id` and returns
the Contribution-ID in the Field `id`.

#### Parameters

| Field (required)     | Type    | Default       | Description                                                                             |
|----------------------| ------- |---------------|-----------------------------------------------------------------------------------------|
| `contact_id`*        | Integer |               | CiviCRM Contact ID                                                                      |
| `campaign`           | String  |               | External ID for donation-relevant campaign                                              |
| `campaign_id`        | Integer |               | Overwrites `campaign`                                                                   |
| `payment_instrument` | String  | `Credit Card` | Supported methods: `Credit Card`, `OOFF`, `PayPal`, `Sofortüberweisung`, `EPS`          |
| `total_amount`*      | Float   |               | Format: `0.00`, amount of donation                                                      |
| `currency`           | String  | `EUR`         |                                                                                         |
| `financial_type_id`  | Integer | `1`           | 1 = Donation, 2 = Member Dues                                                           |
| `source`             | String  | `OSF`         | Source of the donation (not used yet)                                                   |
| `iban`*              | String  |               | IBAN (only for payment_instrument=`OOFF`)                                               |
| `bic`                | String  |               | BIC (only for payment_instrument=`OOFF`)                                                |
| `gp_iban`            | String  |               | IBAN for Organisation account, will be created, if not exist                            |
| `trxn_id`            | String  |               | Transaction-ID for donation, if payment_instrument is not `OOFF`. Unique                |
| `utm_source`         | String  |               | UTM Source. Identifies which site sent the traffic                                      |
| `utm_medium`         | String  |               | UTM Medium. Identifies what type of link was used                                       |
| `utm_campaign`       | String  |               | UTM Campaign. Identifies a specific promotion or strategic campaign                     |
| `utm_content`        | String  |               | UTM Content. Identifies what specifically was clicked to bring the user to the site     |
| `utm_id`             | String  |               | UTM Id. Identification parameter used to track campaign performance in Google Analytics |
| `utm_term`           | String  |               | UTM Term. Identifies search terms                                                       |
| `failed`             | Boolean | false         | Mark donation as failed in order to accept failed donation attempts                     |
| `cancel_reason`      | String  |               | Cancel reason of failed donation                                                        |
<small>**\* mandatory field**</small>

#### Return Value

Returns the Contribution-ID in the field `id`

---

### Activity `(OSF.order)`

#### Description
The `OSF.order` endpoint allows clients to transfer order data from ordered  webshop-items into CiviCRM. CiviCRM matches the submitted data
to existing contacts based on the `contact_id` and returns the Activity-ID in the Field `id`.

Additional contact and address parameters associated with the order can be
set and may be used to provide a shipping address differing from the primary
contact address.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_id`*                | Integer       | | CiviCRM Contact ID |
| `campaign`                   | String        | | External ID for donation-relevant campaign |
| `campaign_id`                | Integer       | | Overwrites `campaign` |
| `order_type`*                | Integer       | | Use `osf.getproducts` to get the right product value (use the `value` NOT the `id`) |
| `order_count`*               | Integer       | | Amount of ordered items |
| `linked_contribution`        | Integer       | | CiviCRM ID of the contribution which paid the order (has to be empty if `linked_membership` is set!) |
| `linked_membership`          | Integer       | | CiviCRM ID of the membership which pays the order (has to be empty if `linked_contribution` is set!) |
| `shirt_size`                 | String        | | If order type = `11` then choose: `S`, `M`, `L`, `XL` |
| `shirt_type`                 | String        | | If order type = `11` then choose: `M`, `W` |
| `payment_received`           | Boolean       | | Set `true`, if the order is already payed |
| `multi_purpose`              | String        | | Field for additional information, where there is no parameter yet in the API implemented |
| <s>`subject`</s>             | <s>String</s> | <s>`Webshop Order`</s> | <s>Title of the created activity</s> |
| `civi_referrer_contact_id`   | Integer       | | CiviCRM Contact ID of the referrer. If provided, it will be used to fetch contact details like `first_name`. This overwrites any values provided in the contact detail fields. |
| `first_name`                 | String        | | |
| `last_name`                  | String        | | |
| `gender_id`                  | String        | | Values available: `Male`, `Female`, `Other`. <br />**Note:** These values are case-sensitive. |
| `email`                      | String        | | Need to be valid format `%@%.%` otherwise it is garbaged by API |
| `phone`                      | String        | | Is normalized by normalize extension |
| `street_address`             | String        | | Street name and house number separated by one space |
| `city`                       | String        | | |
| `postal_code`                | String        | | |
| `country`                    | String        | | Country code according to ISO 3166-1 alpha-2 |
<small>**\* mandatory field**</small>

#### Return Value

Returns the Activity-ID of the order in the field `id`

---

### Membership `(OSF.contract)`

#### Description
The `OSF.contract` endpoint allows clients to transfer all necessary data to create a membership in CiviCRM. CiviCRM matches the submitted data
to existing contacts based on the `contact_id` and returns
the Membership-ID in the Field `id`.

#### Parameters

| Field (required)           | Type    | Default                                                                   | Description                                                                                                                                   |
|----------------------------| ------- |---------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------|
| `contact_id`*              | Integer |                                                                           | CiviCRM Contact ID                                                                                                                            |
| `campaign`                 | String  |                                                                           | External ID for membership source campaign                                                                                                    |
| `campaign_id`              | Integer |                                                                           | Overwrites `campaign`                                                                                                                         |
| `frequency`*               | Integer |                                                                           | Number of debits a year for this membership (must always be 12 for PSP memberships!)                                                          |
| `amount`*                  | Float   |                                                                           | Amount for each debit, format: `0.00`                                                                                                         |
| `currency`                 | String  | CiviCRM default                                                           |                                                                                                                                               |
| `membership_type_id`       | Integer | Value of the `gpapi_membership_type_id` CiviCRM setting (defaults to `1`) | CiviCRM Membership Type ID (get the IDs from the CRM)                                                                                         |
| `iban`*                    | String  |                                                                           | IBAN or PSP payment token (e.g. Adyen `shopperReference`)                                                                                     |
| `bic`[^2]                  | String  |                                                                           | BIC or PSP account name (e.g. Adyen merchant name)                                                                                            |
| `payment_received`         | Boolean | `false`                                                                   | If `true`, create a contribution for the first payment and move the next debit about one month further (ONLY to be used for PSP memberships!) |
| `payment_service_provider` | String  | `SEPA`                                                                    | Choose from: `adyen` or `payu`                                                                                                                |
| `trxn_id`                  | String  |                                                                           | Transaction-ID from donation if `payment_received` = `true`, Unique field                                                                     |
| `payment_instrument`       | String  | `RCUR`                                                                    | If sending PSP-Membership choose: `Credit Card`, `PayPal`, `Sofortüberweisung`, `EPS`, if SEPA-Membership choose: `RCUR`                      |
| `referrer_contact_id`      | Integer |                                                                           | CiviCRM Contact ID of the Referrer (MemberGetMember programme). Invalid values will be accepted and logged for further checks                 |
| `utm_source`               | String  |                                                                           | UTM Source. Identifies which site sent the traffic                                                                                            |
| `utm_medium`               | String  |                                                                           | UTM Medium. Identifies what type of link was used                                                                                             |
| `utm_campaign`             | String  |                                                                           | UTM Campaign. Identifies a specific promotion or strategic campaign                                                                           |
| `utm_content`              | String  |                                                                           | UTM Content. Identifies what specifically was clicked to bring the user to the site                                                           |
| `utm_id`                   | String  |                                                                           | UTM Id. Identification parameter used to track campaign performance in Google Analytics                                                       |
| `utm_term `                | String  |                                                                           | UTM Term. Identifies search terms                                                                                                             |
<small>**\* mandatory field**</small>

[^2]:
    BIC is required for PSP memberships, but optional for SEPA.

#### Return Value

Returns the Membership-ID in the field `id`

### Update Contract `(OSF.updatecontract)`

#### Description

Update membership and payment-related information.

#### Parameters

| Field (required)            | Type    | Default    | Description                         |
| --------------------------- | ------- | ---------- | ----------------------------------- |
| `hash`*                     | String  |            | Contact hash                        |
| `contract_id`*              | Integer |            | Contract/Membership ID              |
| `frequency`*                | Integer |            | Number of debits per year, e.g. 12 for monthly |
| `amount`*                   | Float   |            | Amount for each individual debit (i.e. monthly amount for monthly contracts). Format: `1000.00` |
| `payment_instrument`*       | String  |            | One of: `RCUR`, `Credit Card`, `Sofortüberweisung`, `EPS`. **Note:** RCUR is SEPA |
| `payment_service_provider`* | String  |            | One of: `civicrm`, `adyen`. `civicrm` is used for SEPA/RCUR |
| `payment_details`*          | Array   |            | Elements depend on `payment_instrument` and `payment_service_provider` |
| *for payment_instrument=\*, payment_service_provider=adyen:* |
| ↳ `shopper_reference`*      | String  |            | Adyen Shopper Reference |
| ↳ `merchant_account`*       | String  |            | Adyen Merchant Account, e.g. `GreenpeaceAT` |
| *for payment_instrument=RCUR, payment_service_provider=civicrm:* |
| ↳ `iban`*                   | String  |            | IBAN |
| `start_date`                 | Date    | Now       | Date on which the update becomes effective. Defaults to now. Values in the past will automatically be set to the current date. Note: This is not the actual first debit date, which would depend on a combination of the current date, cycle_day, payment instrument and payment service provider and the transaction_details parameter. Format: `YYYY-MM-DD` |
| `currency`                   | String  | CiviCRM default | ISO 4217 currency code. |
| `membership_type`            | String  | Current membership type | AT: One of `Förderer`, `Könige der Wälder`, `Flottenpatenschaft`, `Landwirtschaft`, `Baumpatenschaft`, `arctic defender`, `Guardian of the Ocean`, `Walpatenschaft`, `Atom-Eingreiftrupp`, `Greenpeace for me` |
| `campaign_id`                | Integer |           | CiviCRM campaign ID for the update (not the overall membership) |
| `external_identifier`        | String  |           | Unique identifier of the donation in an external system. Make sure submitted values are either globally unique or use a prefix to partition identifiers, e.g. `ODF-1`. Requests with duplicate values will be rejected. |
| `transaction_details`        | Array   |           | Used for payment providers where the first transaction is performed outside of CiviCRM and the transaction needs to be entered in CiviCRM. Required for some combinations of `payment_instrument` and `payment_service_provider` **if** a transaction was performed (e.g. after entering new payment details) |
| *for payment_instrument=\*, payment_service_provider=adyen:* |
| ↳ `date`*                    | String  |           | Date on which the transaction was performed. Format: `YYYYMMDDHHIISS` (PHP Format: `YmdHis`) |
| ↳ `trxn_id`*                 | String  |           | Adyen merchant reference. Must be unique. |
| ↳ `authorise_response`       | Array   |           | Array of raw Adyen response for the `/authorise` request |
<small>**\* mandatory field**</small>

#### Return Value

Returns the activity ID of the update in the field `id`.

#### Error codes

| Error Code                             | Description                                              |
| -------------------------------------- | -------------------------------------------------------- |
| `unknown_hash`                         | The provided contact hash does not exist or was deleted. |
| `unknown_contract`                     | The contract does not exist or does not belong to this contact. |
| `payment_instrument_unsupported`       | The requested payment instrument is not supported by this API. |
| `payment_service_provider_unsupported` | The requested payment service provider is not supported by this API. |
