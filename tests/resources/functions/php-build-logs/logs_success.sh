set -e

CHARS_128="11111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111111"
CHARS_1024="$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128$CHARS_128"

# 1 MB
echo -n $CHARS_1024

# Exception message length is 1MB max. Logs below ensure build logs arent limited by it

# 2 MB
echo -n $CHARS_1024

# Up to 20MB is valid, so we add more to ensure larger logs are also fine

# 15 MB
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
echo -n $CHARS_1024
