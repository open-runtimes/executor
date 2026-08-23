// Returns a body far larger than a single socket read, so the executor's streaming format has to
// frame it as several length prefixed runs rather than one.
module.exports = async (context) => {
    const size = parseInt(context.req.headers['x-response-size'] ?? '1048576', 10);
    const line = 'A'.repeat(63) + '\n';
    let body = '';
    while (body.length < size) {
        body += line;
    }

    context.log('Produced ' + body.length + ' bytes');

    return context.res.text(body, 200, { 'content-type': 'text/plain' });
}
