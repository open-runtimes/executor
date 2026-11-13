// Answers, then ends the server process, so the restart policy brings the runtime back
module.exports = async (context) => {
    setTimeout(() => process.exit(1), 100);
    return context.res.send('', 500);
};
