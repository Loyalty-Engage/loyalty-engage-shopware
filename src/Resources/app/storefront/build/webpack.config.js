const path = require('path');

module.exports = () => {
    return {
        entry: {
            loyaltycart: path.resolve(__dirname, '../src/main.js'),
        }
    };
};