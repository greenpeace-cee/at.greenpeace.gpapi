## General

This endpoint accepts text messages exchanged with supporters by a
telemarketing agency on our behalf and records them in CiviCRM: messages the
agency sent (`outbound`) and replies it received (`inbound`). It only records
messages and cannot be used to send any.

!!! warning "Authentication differs from the other endpoints"
    This endpoint does **not** use the `api_key` and site `key` described under
    [Authentication](index.md#authentication). It is authenticated with a single
    secret key, provided together with the endpoint URL.

## Endpoints

### Record Message

#### Description

Records a single message, in either direction. Submit one message per request,
as a JSON `POST` body to:

```
https://civicrm.example.com/civicrm/sms/callback?provider_id={provider_id}&key={key}
```

Please contact the database team at Greenpeace CEE to obtain the endpoint URL,
`{provider_id}` and `{key}`.

#### Parameters

The request body must be a JSON object containing the following fields. Any
additional fields are ignored.

| Field (required)  | Type   | Description                                      |
| ----------------- | ------ | ------------------------------------------------ |
| `sender_number`*  | String | Number the message was sent from, in international format (e.g. `+436641234567`). The supporter's number for `inbound` messages, your own for `outbound` ones. Must not be empty. |
| `receiver_number`*| String | Number the message was sent to, in international format. Your own number for `inbound` messages, the supporter's for `outbound` ones. Must not be empty. |
| `message`*        | String | Message text. May be empty or consist only of whitespace — such messages are recorded rather than rejected. |
| `direction`       | String | Either `inbound` for a message received from a supporter, or `outbound` for one sent to a supporter. Defaults to `inbound`. |
| `sent_at`         | String | Date and time the message was sent, in ISO-8601 format (e.g. `2026-10-13T11:22:33+02:00`, or `2026-10-13 11:22:33` to give local time in Austria). Defaults to the time the request is received, so please supply it when forwarding messages in batches. |
| `campaign_id`     | Integer | ID of the campaign the message belongs to, as provided by us. Recorded on the activity. An ID that does not exist is rejected. |
| `message_id`      | String | Optional identifier of the message, unique within your system, max. 250 characters. Supplying it makes delivery safe to retry, see below. |

#### Return Value

On success, the response is `200 OK` with the ID of the recorded activity and
whether the message had already been recorded:

```json
{"activity_id": 4711, "duplicate": false}
```

On failure, the response carries the corresponding HTTP status code and an
error message:

```json
{"error": "Required fields are missing: sender_number"}
```

| Status | Description                                                      | Retry |
| ------ | ---------------------------------------------------------------- | ----- |
| `200`  | The message was recorded                                          | —     |
| `400`  | Malformed request, e.g. invalid JSON, a missing required field or an unknown `campaign_id` | No |
| `401`  | The `key` parameter is missing or does not match                   | No    |
| `500`  | Error while processing the request                                 | Yes   |

`4xx` responses will keep failing until the request or the key is corrected, so
they should be reported rather than retried. A `500` may succeed when retried.

Responses with a status not listed above mean the request did not reach the
endpoint — for example a wrong URL (`403`, `404`), or CiviCRM being unavailable
or in a maintenance window. These may be retried, and their body is not
necessarily JSON.

#### Retrying

If a request fails in a way that may be transient — a `500`, or a dropped
connection with no response at all — it is not possible to tell whether the
message was recorded. Send `message_id` to make retrying safe: the same
`message_id` is only ever recorded once, and a redelivery responds `200 OK`
with `"duplicate": true` and the ID of the activity recorded the first time.

`message_id` only needs to be unique within your own system; it is namespaced
per sender on our side. It is shared between both directions, so an inbound
and an outbound message must not use the same `message_id`. Retries must reuse
the `message_id` of the original request, otherwise the message is recorded
again.

Without a `message_id` no deduplication is possible and a retry will record the
message a second time.

#### Example

```bash
curl -X POST 'https://civicrm.example.com/civicrm/sms/callback?provider_id=1&key={key}' \
  -H 'Content-Type: application/json' \
  -d '{
        "direction": "inbound",
        "sender_number": "+436641234567",
        "receiver_number": "+436991234567",
        "message": "STOP",
        "sent_at": "2026-10-13T11:22:33+02:00",
        "campaign_id": 42,
        "message_id": "9eca31715a1c896cbc1b"
      }'
```

```json
{"activity_id": 4711, "duplicate": false}
```
