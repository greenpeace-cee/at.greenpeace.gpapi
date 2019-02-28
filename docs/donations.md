## General

The donations API, located in the "OSF" namespace, supports the following
features:

- Creating or matching contacts
- Creating one-off donations
- Creating recurring donations (memberships/contracts)
- Creating webshop orders
- Retrieving a list of Web-related campaigns
- Retrieving a list of available webshop products
- Forwarding of Payment Service Provider (PSP) notifications

## Endpoints

### Contacts `(OSF.submit)`

#### Description
The `OSF.submit` endpoint allows clients to transparently get or create contacts
in CiviCRM based on their contact details. CiviCRM matches the submitted data
to existing contacts based on rules defined within CiviCRM and either returns
a new contact or the matching existing contact.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ---------- | ----------------------------------- |
| `contact_type`   | String  | Individual | CiviCRM contact type |
| `first_name`[^1] | String  | | |
| `last_name`[^1]  | String  | | |
| `prefix`         | String  | | @TODO: Gender/prefix gap? |
| `birth_date`[^1] | Date    | | |
| `bpk`[^1]        | String  | | Austrian tax office identifier for individual |
| `email`[^1]      | String  | | |
| `phone`          | String  | | |
| `iban`[^1]       | String  | | Only used as a matching criteria |
| `street_address`[^1] | String | | Street name and house number separated by one space |
| `postal_code`[^1] | String | | |
| `city`           | String  | | |
| `country`        | String  | | Country code according to ISO 3166-1 alpha-2 |
| `newsletter`     | Boolean | 0 | Whether this contact opted-in to the email newsletter |

[^1]:
    At least *one* of the following (combinations of) fields needs to be present:

    - `bpk`
    - `first_name` and `email`
    - `last_name` and `email`
    - `iban` and `birth_date`
    - `first_name` and `last_name` and `postal_code` and `street_address`


#### Return Value

Returns an array where the key `id` contains the CiviCRM Contact ID.