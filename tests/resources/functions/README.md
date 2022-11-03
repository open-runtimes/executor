`php.tar.gz` includes:

- `index.php` - Function that returns JSON with a `payload`, `variable` (from `customVariable`), and `unicode` message.
- `timeout.php` - Simple JSON response `{pass:true}` but with 15 seconds sleep. Should be used to test timeout logic.