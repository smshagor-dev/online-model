import test from 'node:test';
import assert from 'node:assert/strict';
import {automaticCoordinator,automaticSpecialists} from '../src/lib/auto-models.ts';
const model=(slug,status)=>({slug,name:slug,provider:slug,lastTestStatus:status});
test('Auto excludes failed providers and restricted models from delegation',()=>{
 const models=[model('openmindai-super','failed'),model('openmindai-lite','success'),model('openmindai-ultra','success'),model('nvidia-laguna-xs-2.1','failed'),model('nvidia-riva-translate-4b','success'),model('guard','success')];
 const coordinator=automaticCoordinator(models);
 assert.equal(coordinator.slug,'openmindai-lite');
 assert.deepEqual(automaticSpecialists(models,coordinator.provider).map(m=>m.slug),['openmindai-ultra']);
 assert.equal(automaticCoordinator([model('openmindai-lite','failed')]),undefined);
});
