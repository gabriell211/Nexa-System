import { mkdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import sharp from 'sharp';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const assets = path.join(root, 'assets');
const mark = path.join(assets, 'brand-mark.webp');
const navy = '#071B40';
await mkdir(assets, { recursive: true });
const logo = await readFile(mark);

const transparentMark = async (size) => sharp(logo)
  .resize(size, size, { fit: 'contain', kernel: 'lanczos3' })
  .png().toBuffer();

await sharp(logo).resize(256, 256, { fit: 'contain' })
  .png().toFile(path.join(assets, 'brand-mark.png'));

const gradient = Buffer.from(`<svg xmlns="http://www.w3.org/2000/svg" width="1024" height="1024">
  <defs><linearGradient id="blue" x1="0" x2="1" y1="1" y2="0">
    <stop offset="0" stop-color="#061737"/>
    <stop offset="0.55" stop-color="#07377D"/>
    <stop offset="1" stop-color="#075CB5"/>
  </linearGradient></defs>
  <rect width="1024" height="1024" fill="url(#blue)"/>
  <circle cx="940" cy="90" r="360" fill="#0B76E0" fill-opacity="0.18"/>
</svg>`);

await sharp(gradient)
  .composite([{ input: await transparentMark(740), top:142, left:142 }])
  .png().toFile(path.join(assets, 'app-icon.png'));

await sharp({ create:{width:1024,height:1024,channels:4,background:'#00000000'} })
  .composite([{input: await transparentMark(680),top:172,left:172}])
  .png().toFile(path.join(assets,'adaptive-icon.png'));

await sharp({ create:{width:650,height:650,channels:4,background:'#00000000'} })
  .composite([{input:await transparentMark(580),top:35,left:35}])
  .png().toFile(path.join(assets,'splash.png'));

console.log('Nexa Técnico: ícones Android/iOS e imagem de abertura gerados.');
