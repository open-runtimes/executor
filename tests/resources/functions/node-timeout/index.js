module.exports = async (context) => {
    await new Promise((res) => {
        setTimeout(() => {
            res(true);
        }, 60000);
    });

    return context.res.send('OK');
}