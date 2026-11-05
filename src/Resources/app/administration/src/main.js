import enGBSnippets from './snippet/en-GB.json';
import deDeSnippets from './snippet/de-DE.json';
import './module/loyalty-shop-cms';
import './module/personalizations';

const { Locale } = Shopware;

Locale.extend('en-GB', enGBSnippets);
Locale.extend('de-DE', deDeSnippets);
