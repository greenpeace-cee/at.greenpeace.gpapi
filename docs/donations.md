## General

The donations API, located in the "OSF" entity, supports the following
features:

- Creating or matching contacts
- Creating one-off donations
- Creating recurring donations (memberships/contracts)
- Creating webshop orders
- Retrieving a list of Web-related campaigns
- Retrieving a list of available webshop products
- Forwarding of Payment Service Provider (PSP) notifications

A Standard use case would be that the API-using application sends the contact data to Civi to check, if the contact already exists there. If so, an existing contact_id is returned, otherwise a new contact_id is created and returned. Whith this information the application is able to send a contribution or a membership to Civi and connect it with the before communicated contact_id. If the membership or contribution is connected with an order of an item, the application will also create an activity from type `webshop_order`. 

## Endpoints

### Campaigns `(OSF.getcampaigns)`

#### Description
The `OSF.getcampaigns` endpoint allows clients to retrieve a list of all the campaigns in Civi which the OSF is allowed to see. 

#### Parameters
From the return values you shall use for input fields:

- `external_id` for `campaign`
- `id` for `campaign_id`

---

### Option Value `(OSF.getproducts)`

#### Description
The `OSF.getproducts` endpoint allows clients to retrieve a list of all products for the OSF available. 

#### Parameters
From the returned values you have to use for the `order_type` field of the `OSF.order` action the field `value`!

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
| `prefix`         | String  | | Values available: `Herr` or `Frau`, @TODO: Gender/prefix gap? |
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

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_id`*       | Integer | | CiviCRM Contact ID |
| `campaign`          | String  | | External ID for donation-relevant campaign |
| `campaign_id`       | Integer | | Overwrites `campaign` |
| `payment_instrument`| String  | `Credit Card` | Supported methods: `Credit Card`, `OOFF`, `PayPal`, `Sofortüberweisung`, `EPS` |
| `total_amount`*     | Float   | | Format: `0.00`, amount of donation |
| `currency`          | String  | `EUR` | |
| `financial_type_id` | Integer | `1` | 1 = Donation, 2 = Member Dues |
| `source`            | String  | `OSF` | Source of the donation (not used yet) |
| `iban`*             | String  | | IBAN (only for payment_instrument=`OOFF`) |
| `bic`*              | String  | | BIC (only for payment_instrument=`OOFF`)
| `gp_iban`           | String  | | IBAN for Organisation account, will be created, if not exist |
| `trxn_id`           | String  | | Transaction-ID for donation, if payment_instrument is not `OOFF`. Unique |
<small>**\* mandatory field**</small>

#### Return Value

Returns the Contribution-ID in the field `id` 

---

### Activity `(OSF.order)`

#### Description
The `OSF.order` endpoint allows clients to transfer order data from ordered  webshop-items into CiviCRM. CiviCRM matches the submitted data
to existing contacts based on the `contact_id` and returns
the Activity-ID in the Field `id`.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_id`*         | Integer | | CiviCRM Contact ID |
| `campaign`            | String  | | External ID for donation-relevant campaign |
| `campaign_id`         | Integer | | Overwrites `campaign` |
| `subject`             | String  | `Webshop Order` | Title of the created activity |
| `order_type`*         | Integer | | Use `osf.getproducts` to get the right product value (use the `value` NOT the `id`) |
| `order_count`*        | Integer | | Amount of ordered items |
| `linked_contribution` | Integer | | CiviCRM ID of the contribution which paid the order (has to be empty if `linked_membership` is set!) |
| `linked_membership`   | Integer | | CiviCRM ID of the membership which pays the order (has to be empty if `linked_contribution` is set!) |
| `shirt_size`          | String  | | If order type = `11` then choose: `S`, `M`, `L`, `XL` |
| `shirt_type`          | String  | | If order type = `11` then choose: `M`, `W` |
| `payment_received`    | Boolean | | Set `true`, if the order is already payed |
| `multi_purpose`       | String  | | Field for additional information, where there is no parameter yet in the API implemented |
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

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_id`*              | Integer | | CiviCRM Contact ID |
| `campaign`                 | String  | | External ID for membership source campaign |
| `campaign_id`              | Integer | | Overwrites `campaign` |
| `frequency`*               | Integer | | Number of debits a year for this membership (ONLY to be used for NON PSP memberships!) |
| `amount`*                  | Float   | | Amount for each debit, format: `0.00` |
| `currency`                 | String  | CiviCRM default |  |
| `membership_type_id`*      | Integer | | CiviCRM Membership Type ID (get the IDs from the CRM) |
| `iban`*                    | String  | | IBAN or PSP payment token (e.g. Adyen `shopperReference`) |
| `bic`*                     | String  | | BIC or PSP account name (e.g. Adyen merchant name) |
| `payment_received`         | Boolean | `false` | If `true`, create a contribution for the first payment and move the next debit about one month further (ONLY to be used for PSP memberships!) |
| `payment_service_provider` | String  | `SEPA` | Choose from: `adyen` or `payu`  |
| `trxn_id`                  | String  | | Transaction-ID from donation if `payment_received` = `true`, Unique field |
| `payment_instrument`       | String  | `RCUR` | If sending PSP-Membership choose: `Credit Card`, `PayPal`, `Sofortüberweisung`, `EPS`, if SEPA-Membership choose: `RCUR` |
| `referrer_contact_id`      | Integer | | CiviCRM Contact ID of the Referrer (MemberGetMember programme). Invalid values will be accepted and logged for further checks |
<small>**\* mandatory field**</small>

#### Return Value

Returns the Membership-ID in the field `id` 



