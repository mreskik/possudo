# Order Notif

Notifikasi order masuk dari **sumber eksternal** (sudomobile + Kiosk) yang belum dikonfirmasi kasir — **bukan** notifikasi order yang dibuat langsung dari POS (yang tidak butuh konfirmasi, karena kasir sendiri yang input).

## Pending

```
GET /api/order-notif/pending
```

Response:
```json
{
  "code": 0,
  "data": [
    {
      "order_number": "MBTB2026101214350001",
      "order_name": "Budi",
      "customer_phone_number": "081234567890",
      "total_item": 2,
      "total_billing": "138000.00",
      "order_in": "2026-10-12T14:35:00+07:00",
      "order_source": "mobile"
    },
    {
      "order_number": "KOTB2026101214400002",
      "order_source": "kiosk",
      "...": "..."
    }
  ]
}
```

- **Gabungan 2 sumber**: order dari sudomobile (`mb_order`, `flag_confirm = false`) dan Kiosk (`tr_kiosk_order_notif`, `flag_confirm = false`) — dibedakan field `order_source` (`"mobile"`/`"kiosk"`).
- Order dari POS sendiri **tidak pernah muncul** di sini — ini khusus order yang masuk tanpa campur tangan kasir langsung.

## Confirm

```
POST /api/order-notif/confirm
```

**Request:** `{ "order_number": "MBTB2026101214350001" }`

**Response:** `{ "code": 0, "data": "success" }`

- Set `flag_confirm = true` di tabel sumbernya (`mb_order` **atau** `tr_kiosk_order_notif`, dicoba keduanya — hanya salah satu yang benar-benar match).
- `order_number` kosong → `{ "code": 100, "data": "order_number wajib diisi" }` (pesan masih Bahasa Indonesia — belum ikut task translate response error sebelumnya, di luar scope dokumen ini).

## Alur Implementasi

Dipanggil **polling** (bukan WebSocket/event-driven) dari frontend — tiap tick, hasil `pending` dipakai spawn toast notifikasi baru untuk order yang belum pernah ditampilkan. Kasir dismiss toast → `confirm(order_number)` dipanggil, order itu tidak akan muncul lagi di polling berikutnya.

## Sumber

- `app/Http/Controllers/OrderNotifController.php` — `pendingNotif()`, `confirm()`.
- `app/Services/OrderNotifServices.php` — `getPendingNotif()`, `confirm()`.
- Frontend: `src/composables/useOrderNotif.ts`.

## Status

Didokumentasikan 2026-10-12, berdasar pembacaan kode langsung (belum ada verifikasi ulang lewat HTTP request live di sesi ini).
