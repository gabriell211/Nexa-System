import { access, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)),'..');
const dir = path.join(root,'assets');
const expected = {
  'brand-mark.png':[256,256],
  'app-icon.png':[1024,1024],
  'adaptive-icon.png':[1024,1024],
  'splash.png':[650,650],
};
for (const [name, [width,height]] of Object.entries(expected)) {
  const file = path.join(dir,name);
  await access(file);
  const metadata = await sharp(file).metadata();
  if (metadata.width !== width || metadata.height !== height || metadata.format !== 'png') {
    throw new Error(`Asset inválido: ${name} (${metadata.width}x${metadata.height}, ${metadata.format})`);
  }
}
const source = await readFile(path.join(dir,'brand-mark.webp'));
if (source.subarray(0,4).toString() !== 'RIFF' || source.subarray(8,12).toString() !== 'WEBP') {
  throw new Error('O arquivo mestre da marca está corrompido.');
}
console.log('Nexa Técnico: 5 arquivos de identidade verificados.');
