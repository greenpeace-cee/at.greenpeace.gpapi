## General

The Engage API, located in the "Engage" entity, supports the following
features:

- Creating or matching contacts
- Creating activities from type `petition_signature`
- Retrieving a list of petition related campaigns
- Retrieving a list of available media for how a petition can be transferred

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
The `Engage.signpetition` endpoint allows clients to transparently get or create contacts in CiviCRM based on their contact details. CiviCRM matches the submitted data to existing contacts based on rules defined within CiviCRM and either returns a new contact or the matching existing contact and then creating an activity of type `petition_signature`.

#### Parameters

| Field (required)       | Type    | Default      | Description                                                                                                                                                                                         |
|------------------------|---------|--------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `contact_type`         | String  | `Individual` | CiviCRM contact type                                                                                                                                                                                |
| `first_name`[^1]       | String  |              |                                                                                                                                                                                                     |
| `last_name`[^1]        | String  |              |                                                                                                                                                                                                     |
| `prefix`               | String  |              | Values available: `Herr`, `Frau`, `Familie`. <br /> **Note:** These values may be language- and instance-specific. It is preferred to set `gender_id` and leave this field empty whenever possible. |
| `gender_id`            | String  |              | Values available: `Male`, `Female`, `Other`. <br />**Note:** These values are case-sensitive.                                                                                                       |
| `birth_date`[^1]       | Date    |              | Format: `YYYY-MM-DD`                                                                                                                                                                                |
| `hash`                 | String  |              | CiviCRM contact hash                                                                                                                                                                                |
| `bpk`[^1]              | String  |              | Austrian tax office identifier for `contact_type` = `Individual`                                                                                                                                    |
| `email`[^1]            | String  |              | Need to be valid format `%@%.%` otherwise it is garbaged by API                                                                                                                                     |
| `phone`                | String  |              | Is normalized by normalize extension                                                                                                                                                                |
| `street_address`[^1]   | String  |              | Street name and house number separated by one space                                                                                                                                                 |
| `postal_code`[^1]      | String  |              |                                                                                                                                                                                                     |
| `city`                 | String  |              |                                                                                                                                                                                                     |
| `country`              | String  |              | Country code according to ISO 3166-1 alpha-2                                                                                                                                                        |
| `external_identifier`  | String  |              | Unique identifier of the petition signature in an external system. Make sure submitted values are either globally unique or use a prefix to partition identifiers.[^2]                              |
| `medium_id`            | String  |              | According `value` field from `Engage.getmedia`                                                                                                                                                      |
| `campaign`             | String  |              | External ID for donation-relevant campaign                                                                                                                                                          |
| `campaign_id`          | Integer |              | Overwrites `campaign`                                                                                                                                                                               |
| `petition_id`          | Integer |              | Overwrites `campaign` and `campaign_id` otherwise the DEFAULT-petition for the choosen campaign is used                                                                                             |
| `petition_dialoger`    | Integer |              | CiviCRM ID of the Direct Dialog Canvasser                                                                                                                                                           |
| `signature_date`       | String  |              | Date of the petition_signature, Format: `YYYYMMDDHHIISS` (PHP Format: `YmdHis`)                                                                                                                     |
| `newsletter`           | Boolean | `0`          | Whether this contact opted-in to the "Community NL" email newsletter                                                                                                                                |
| `xcm_profile`          | String  | `engagement` | XCM profile to be used for contact matching                                                                                                                                                         |
| `utm_source`           | String  |              | UTM Source. Identifies which site sent the traffic                                                                                                                                                  |
| `utm_medium`           | String  |              | UTM Medium. Identifies what type of link was used                                                                                                                                                   |
| `utm_campaign`         | String  |              | UTM Campaign. Identifies a specific promotion or strategic campaign                                                                                                                                 |
| `utm_content`          | String  |              | UTM Content. Identifies what specifically was clicked to bring the user to the site                                                                                                                 |
| `utm_id`               | String  |              | UTM Id. Identification parameter used to track campaign performance in Google Analytics                                                                                                             |
| `utm_term`             | String  |              | UTM Term. Identifies search terms                                                                                                                                                                   |
| `geoip_country_id`     | String  |              | **GeoIP** country code according to ISO 3166-1 alpha-2                                                                                                                                              |
| `location`             | String  |              | Location/Identifier of the petition source, e.g. form URL or ID                                                                                                                                     |

[^1]:
    At least *one* of the following (combinations of) fields needs to be present:

    - `bpk`
    - `phone`
    - `first_name` and `email`
    - `last_name` and `email`
    - `first_name` and `last_name` and `postal_code` and `street_address`

[^2]:
    Uniqueness of this value is enforced. When values are submitted more than once,
    the error "Duplicate value for external_identifier" will be returned and the
    request will be dropped.

#### Return Value

Returns the Contact-ID in the field `id` and in the `values` array an object with the created IDs with their respective keys.

---

### Campaigns `(Engage.getcampaigns)`

#### Description

The `Engage.getcampaigns` endpoint allows clients to retrieve a list of all root campaigns and campaigns that are enabled for for the Engagement Tool
(Flag `campaign_et_enabled`).

#### Parameters

None.

#### Return Value

Returns a result object with a `values` array containing the requested campaigns.
