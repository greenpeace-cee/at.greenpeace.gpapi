## General

Greenpeace Custom API (GPAPI) is a CiviCRM extension and uses the APIv3
infrastructure provided by CiviCRM. The goal for this extension is to bundle
various CiviCRM APIs into a small set of easy-to-implement APIs that perform a
specific task rather than provide a generic interface to various entities in
CiviCRM.

Consumers of this API do not need to be familiar with CiviCRM as a whole.
In most scenarios, users of this API would only have access to the APIs defined
in this extension (or even just a subset), rather than all APIs that are part of
CiviCRM.

## Usage

CiviCRM offers a number of ways to consume APIs. For typical use-cases of
this extension, the [REST interface] is the most convenient option.

!!! tip "Client Library"

    For client applications written in PHP, CiviCRM offers an [object-oriented API client].

The REST interface accepts GET and POST parameters and can return JSON or XML.
A sample `curl` request to the `OSF.getcampaigns` endpoint may look like this:

```bash
curl -X POST 'https://civicrm.example.com/sites/all/modules/civicrm/extern/rest.php' -d 'entity=OSF&action=getcampaigns&api_key={api_key}&key={site_key}&json={"max_depth":10,"version":3}'
```

To obtain the endpoint URL for staging and production environments, please
contact the database team at Greenpeace CEE.

!!! warning "Network Access"
    The API endpoints, both for the staging and production environments,
    are behind a firewall, i.e. not publicly accessible. Network-level
    access will need to be set up, preferably via IP whitelisting. This
    generally works best with a static IP address (range) for API clients.

## Authentication

To authenticate requests to the API, two secret parameters are needed:

- The per-user API key (`api_key`)
- The site key (`key`)

Please contact the database team at Greenpeace CEE to obtain these secrets.

## Parameter Types

API parameters can be of the following types:

- **String**: Any sequence of characters
- **Boolean**: `0` or `1`
- **Date**: Date in format `YYYY-MM-DD` or `YYYYMMDD`
- **DateTime**: Date and time in format `YYYY-MM-DD HH:MM:SS` or `YYYYMMDDHHMMSS`
- **Integer**: Positive or negative natural number
- **Decimal**: Positive or negative decimal number with a maximum of two digits
  to the right of the decimal point

## Response Format

The REST API response can be either JSON or XML. The default format is XML. The
parameter `json=1` can be added to request a JSON response

API responses may contain the following fields:

| Field | Type | Description |
| --------------- | ------------- | ----------------------------------------- |
| `is_error` | `boolean`  | Whether an error occurred during the request. Errors can be anything from data validation issues (missing or invalid parameters) to runtime errors. |

In certain situations, for example during maintenance windows, the API may also
use HTTP status codes in the `4xx` and `5xx` range to indicate that there was an
error while processing the request. In these cases, the response might not
contain the fields described in this section.

[REST interface]: https://docs.civicrm.org/dev/en/latest/api/usage/#rest
[object-oriented API client]: https://docs.civicrm.org/dev/en/latest/api/usage/#php-classapiphp