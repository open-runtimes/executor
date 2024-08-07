module.exports = async (context) => {
    context.res.start();
    context.res.writeText('OK1');
    await new Promise(resolve => setTimeout(resolve, 5000));
    context.res.writeText('OK2');
    return context.res.end();
};