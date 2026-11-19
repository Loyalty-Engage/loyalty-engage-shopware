module.exports = () => {
    return {
        // ZIP installs must not depend on another build's shared Webpack module IDs.
        optimization: {
            runtimeChunk: false,
            splitChunks: false,
        }
    };
};
