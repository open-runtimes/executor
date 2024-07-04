module.exports = async (context) => {
    return context.res.binary(context.req.bodyBinary);
}