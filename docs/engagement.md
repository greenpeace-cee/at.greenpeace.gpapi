## General

The Engage API, located in the "Engage" namespace, supports the following
features:

- Creating or matching contacts
- Creating activities from type `petition_signature` 
- Retrieving a list of petition related campaigns
- Retrieving a list of available media for how a petiton can be transferred

A Standard use case would be that the API-using application sends the contact data and petition data to Civi. If the contact already exists in Civi, the petition signature activity is created at that found contact, otherwise a new contact is created and the petition signature activity is created at that found contact. 

## Endpoints

### Surveys `(Engage.getpetitions)`

#### Description
The `Engage.getpetitions` endpoint allows clients to retrieve a list of all the petitions in Civi. 

#### Parameters
From the return values you shall use for input fields:

- `id` for `petition_id`

---

### Option Value `(Engage.getmedia)`

#### Description
The `Engage.getmedia` endpoint allows clients to retrieve a list of all contact media used by the Engagement Tool. 

#### Parameters
From the returned values you have to use for the `medium_id` field of the `Engage.getmedia` action the field `value` NOT the returned field `id`!

---

### Contacts `(Engage.signpetition)`

#### Description
The `Engage.signpetition` endpoint allows clients to transparently get or create contacts in CiviCRM based on their contact details. CiviCRM matches the submitted data to existing contacts based on rules defined within CiviCRM and either returns a new contact or the matching existing contact and then creating an activity from type `petition_signature`.

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
| `street_address`[^1] | String | | Street name and house number separated by one space |
| `postal_code`[^1] | String | | |
| `city`           | String  | | |
| `country`        | String  | | Country code according to ISO 3166-1 alpha-2 |
| `medium_id`      | String  | | According `value` field from `Engage.getmedia` |
| `campaign`       | String  | | External ID for donation-relevant campaign |
| `campaign_id`    | Integer | | Overwrites `campaign` |
| `petition_id`    | Integer | | Overwrites `campaign` and `campaign_id` otherwise the DEFAULT-petition for the choosen campaign is used |
| `petition_dialoger` | Integer | | CiviCRM ID of the Direct Dialog Canvasser |
| `signature_date` | String  | | Date of the petition_signature, Format: `YYYYMMDDHHIISS` (PHP Format: `YmdHis`) |
| `newsletter`     | Boolean | `0` | Whether this contact opted-in to the "Community NL" email newsletter |

[^1]:
    At least *one* of the following (combinations of) fields needs to be present: (Stimmt das wirklich? Nochmal nachsehen!)

    - `bpk`
    - `first_name` and `email`
    - `last_name` and `email`
    - `first_name` and `last_name` and `postal_code` and `street_address`

#### Return Value

Returns the Contact-ID in the field `id` and in the `values` array an object with the created IDs with their respective keys.

---
