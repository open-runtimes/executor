module.exports = async (context) => {
    const start = Date.now();
    while (Date.now() - start < 60000) {
        continue;
    }

    return context.res.send('OK');
}