const fs = require('fs');
const path = require('path');

function flattenObject(ob) {
  var toReturn = {};
  for (var i in ob) {
    if (!ob.hasOwnProperty(i)) continue;
    if ((typeof ob[i]) == 'object' && ob[i] !== null) {
      var flatObject = flattenObject(ob[i]);
      for (var x in flatObject) {
        if (!flatObject.hasOwnProperty(x)) continue;
        toReturn[i + '.' + x] = flatObject[x];
      }
    } else {
      toReturn[i] = ob[i];
    }
  }
  return toReturn;
}

const frLocalePath = '/Users/alexisnanou/Works/billeterie/tiketi/resources/js/locales/fr';

const processFile = (vueFilePath, jsonFiles, prefix) => {
  let content = fs.readFileSync(vueFilePath, 'utf8');
  
  if (!content.includes('import { useI18n }')) {
    content = content.replace(
      /import \{([^}]+)\} from 'vue';/,
      "import {$1} from 'vue';\nimport { useI18n } from 'vue-i18n';"
    );
  }
  if (!content.includes('const { t } = useI18n();')) {
    content = content.replace(
      /(const props = defineProps|const form = useForm)/,
      "const { t } = useI18n();\n\n$1"
    );
  }

  let allKeys = {};
  for (const jsonFile of jsonFiles) {
    const data = JSON.parse(fs.readFileSync(path.join(frLocalePath, jsonFile), 'utf8'));
    Object.assign(allKeys, flattenObject(data));
  }

  // Sort strings by length descending to replace longer strings first
  const sortedStrings = Object.keys(allKeys)
    .filter(k => typeof allKeys[k] === 'string' && allKeys[k].length > 1 && !allKeys[k].includes('{'))
    .sort((a, b) => allKeys[b].length - allKeys[a].length);

  for (const key of sortedStrings) {
    const text = allKeys[key];
    
    // Replace in tags: >TEXT< to >{{ $t('key') }}<
    // We need to escape special regex chars in text
    const escapedText = text.replace(/[-\/\\^$*+?.()|[\]{}]/g, '\\$&');
    
    // Template text
    const tagRegex = new RegExp(`>\\s*${escapedText}\\s*<`, 'g');
    content = content.replace(tagRegex, `>{{ $t('${key}') }}<`);

    // Attributes like title="TEXT" or placeholder="TEXT" or value="TEXT"
    const attrRegex = new RegExp(`(\\b(?:title|placeholder|value|label))="${escapedText}"`, 'g');
    content = content.replace(attrRegex, `:$1="$t('${key}')"`);
    
    // Also matching 'TEXT' inside JS if applicable (like in setup) but that's risky. Let's stick to template mostly.
  }

  fs.writeFileSync(vueFilePath, content);
  console.log(`Processed ${vueFilePath}`);
};

const settingsFiles = [
  'Enterprise.vue',
  'Devices.vue',
  'Index.vue',
  'Loyalty.vue'
];

settingsFiles.forEach(file => {
  const fp = path.join('/Users/alexisnanou/Works/billeterie/tiketi/resources/js/Pages/Admin/Settings', file);
  if (fs.existsSync(fp)) {
    processFile(fp, ['admin_settings.json']);
  }
});

