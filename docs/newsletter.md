## General

The Newsletter API, located in the "Newsletter" entity, supports the following features:

- Creating or matching contacts
- Retrieving a list of newsletter groups

A Standard use case would be that the API-using application sends the contact data and `group_ids` to Civi. If the contact already exists in Civi, the newsletter group(s) will be registered at that found contact, otherwise a new contact is created and the newsletter group(s) will be registered with the newly created contact. 

## Endpoints

### Groups `(Newsletter.getgroups)`

#### Description
The `Newsletter.getgroups` endpoint allows clients to retrieve a list of all available newsletter groups in Civi. 

#### Parameters
From the return values you shall use for input fields:

- `id` for `goup_ids`

---

### Contacts `(Newsletter.subscribe)`

#### Description
The `Newsletter.subscribe` endpoint allows clients to transparently get or create contacts in CiviCRM based on their contact details. CiviCRM matches the submitted data to existing contacts based on rules defined within CiviCRM and register the newsletter groups sent with the call there or a new contact is created and the transfered newsletter groups are registered with the newly created contact.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ----------- | ----------------------------------- |
| `contact_type`   | String  | `Individual` | CiviCRM contact type |
| `first_name`     | String  | | |
| `last_name`      | String  | | |
| `prefix`         | String  | | Values available: `Herr` or `Frau`, @TODO: Gender/prefix gap? |
| `birth_date`     | Date    | | Format: `YYYY-MM-DD` |
| `bpk`            | String  | | Austrian tax office identifier for `contact_type` = `Individual` |
| `email`*         | String  | | Need to be valid format %@%.% otherwise it is garbaged by API |
| `phone`          | String  | | Is normalized by normalize extension |
| `street_address` | String  | | Street name and house number separated by one space |
| `postal_code`    | String  | | |
| `city`           | String  | | |
| `country`        | String  | | Country code according to ISO 3166-1 alpha-2 |
| `group_ids`      | String  | | According to `Newsletter.getgroups` IDs, comma separated (21-Community NL) |
<small>**\* mandatory field**</small>

#### Return Value

Returns the Contact-ID in the field `id` and in the `values` array an object with the created IDs with their respective keys.

---

### Groups `(Newsletter.unsubscribe)`

#### Description
The `Newsletter.unsubscribe` endpoint allows clients to unsubscribe a contact from a given newsletter group. CiviCRM matches the submitted data to an existing `contact_id` and deregister the newsletter groups sent with the call.

#### Parameters

| Field (required) | Type    | Default    | Description                         |
| ---------------  | ------- | ----------- | ----------------------------------- |
| `contact_id`*    | Integer | | CiviCRM Contact ID |
| `group_ids`      | String  | | According to `Newsletter.getgroups` IDs, comma separated (f.e. 21-Community NL) |
| `opt_out`        | Boolean | `0` | `1` means that the contact shall not receive any mass mailing at all anymore! |

#### Return Value

Returns a failure or success message.

---