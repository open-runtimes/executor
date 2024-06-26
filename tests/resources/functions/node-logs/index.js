module.exports = async (context) => {
    let log1kb = '';

    for(let i = 0; i < 1023; i++) { // context.log adds a new line character
        log1kb += "A";
    }

    // 1MB * bodyText log
    for(let i = 0; i < 1024 * (+context.req.bodyText); i++) {
        context.log(log1kb);
    }

    return context.res.send('OK');
}