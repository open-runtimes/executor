module.exports = async (context) => {
    const size = parseInt(context.req.headers['x-response-size'] ?? '1048576', 10);
    const line = 'A'.repeat(63) + '\n';
    let body = '';
    while (body.length < size) {
        body += line;
    }

    return context.res.text(body, 200, { 'content-type': 'text/plain' });
}
