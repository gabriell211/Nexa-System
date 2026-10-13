import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
  testDir:'./e2e',
  timeout:35_000,
  expect:{ timeout:12_000 },
  fullyParallel:false,
  retries:process.env.CI ? 1 : 0,
  reporter:process.env.CI ? [['github'],['html',{open:'never'}]] : 'list',
  use:{
    baseURL:'http://127.0.0.1:5173',
    trace:'retain-on-failure',
    screenshot:'only-on-failure',
    video:'retain-on-failure',
  },
  projects:[
    {name:'desktop-chromium',use:{...devices['Desktop Chrome']}},
    {name:'mobile-chromium',use:{...devices['Pixel 7']}},
  ],
});
