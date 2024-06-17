module.exports = async (context) => {
    return context.res.binary(Buffer.from((Uint8Array.from([0, 10, 255]))));
}