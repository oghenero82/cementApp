import { cp, mkdir, rm } from 'node:fs/promises';
await mkdir('public/assets', { recursive: true });
await rm('public/assets/cement', { recursive: true, force: true });
await cp('frontend/src', 'public/assets/cement', { recursive: true });
console.log('Built frontend modules to public/assets/cement');
