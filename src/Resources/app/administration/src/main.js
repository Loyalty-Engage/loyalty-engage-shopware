import enGBSnippets from './snippet/en-GB.json';
import deDeSnippets from './snippet/de-DE.json';

const { Locale } = Shopware;

Locale.extend('en-GB', enGBSnippets);
Locale.extend('de-DE', deDeSnippets);
