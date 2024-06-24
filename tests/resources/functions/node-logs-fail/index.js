module.exports = async (context) => {
    let log1kb = '';

    for(let i = 0; i < 1024; i++) {
        log1kb += "A";
    }

    //1MB log
    for(let i = 0; i < 1024 * (+context.req.bodyText); i++) {
        context.log(log1kb);
    }

    throw new Error('This is an error message');

    return context.res.send('OK');
}