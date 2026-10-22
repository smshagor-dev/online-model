import test from "node:test";
import assert from "node:assert/strict";
import {runConversationTools} from "../src/lib/conversation-tools.ts";
const sse = (delta, reason) => new Response(`data: ${JSON.stringify({choices:[{delta,finish_reason:reason}]})}\n\ndata: [DONE]\n\n`,{headers:{"Content-Type":"text/event-stream"}});
const call = (name,input,id) => sse({tool_calls:[{index:0,id,type:"function",function:{name,arguments:JSON.stringify(input)}}]},"tool_calls");
const base = () => ({model:"enabled/coordinator",apiKey:"fixture-only",messages:[{role:"system",content:"context"},{role:"user",content:"Compare sources then create a PDF"}],signal:new AbortController().signal,specialists:[{slug:"code",name:"OpenMindAI Code",provider:"enabled/code"}],web:async()=>({sources:[]}),artifact:async()=>({ok:true,id:1}),progress:()=>{},delta:()=>{}});

test("native tools consume previous results and execute multiple tools before the streamed answer",async()=>{
 const requests=[],actions=[],progress=[],text=[];
 const responses=[call("web_search",{query:"UAV security evidence"},"search1"),call("read_page",{url:"https://example.org/paper"},"read1"),call("create_artifact",{kind:"document_create",prompt:"Evidence summary",format:"pdf"},"file1"),sse({content:"The report is ready with cited evidence."},"stop")];
 const result=await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},
 web:async input=>{actions.push(input.action);return {sources:[{url:"https://example.org/paper",title:"Paper"}],page_text:"Verified evidence"};},
 artifact:async input=>{actions.push(input.kind);return {ok:true,id:44};},progress:label=>progress.push(label),delta:chunk=>text.push(chunk)});
 assert.deepEqual(actions,["search","read","document_create"]);
 assert.equal(result.calls,3);
 assert.match(text.join(""),/report is ready/);
 assert.ok(requests[1].messages.some(m=>m.role==="tool" && m.tool_call_id==="search1"));
 assert.ok(requests[3].messages.some(m=>m.role==="tool" && m.content.includes('44')));
 assert.equal(requests[0].tools.length,4);
 assert.ok(progress.includes("Searching the web"));
});

test("tool failures become explicit results and duplicate artifacts are not executed twice",async()=>{
 let generated=0; const requests=[];
 const responses=[call("create_artifact",{kind:"image",prompt:"A forest"},"a"),call("create_artifact",{kind:"image",prompt:"A forest"},"b"),sse({content:"The image service is unavailable."},"stop")];
 await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},artifact:async()=>{generated++;throw Error("private provider error");}});
 assert.equal(generated,1);
 const evidence=JSON.stringify(requests[2].messages);
 assert.match(evidence,/already ran/);
 assert.doesNotMatch(evidence,/private provider error/);
});

test("delegation validates the enabled catalog and cancellation prevents execution",async()=>{
 const responses=[call("ask_specialist",{model:"disabled",task:"Write code"},"a"),sse({content:"That specialist is unavailable."},"stop")];
 let requests=0;
 await runConversationTools({...base(),fetcher:async()=>{requests++;return responses.shift();}});
 assert.equal(requests,2);
 const controller=new AbortController();controller.abort();
 await assert.rejects(()=>runConversationTools({...base(),signal:controller.signal,fetcher:async()=>{throw Error("must not execute");}}));
});

test("long answers continue without replaying completed tools",async()=>{
 const requests=[];
 const responses=[call("create_artifact",{kind:"document_create",prompt:"Report"},"file1"),sse({content:"First half. "},"length"),sse({content:"Final half."},"stop")];
 let generated=0;
 const result=await runConversationTools({...base(),fetcher:async(url,init)=>{requests.push(JSON.parse(init.body));return responses.shift();},artifact:async()=>{generated++;return {ok:true,id:8};}});
 assert.equal(result.text,"First half. Final half.");
 assert.equal(generated,1);
 assert.ok(requests[2].messages.some(m=>m.role==="tool" && m.tool_call_id==="file1"));
});

test("coding delegation uses the enabled specialist and returns its evidence to the coordinator", async()=>{
 const requests=[];
 let round=0;
 await runConversationTools({...base(),model:"nvidia/nemotron-3.5-lightning-30b-a3b",fetcher:async(url,init)=>{
  const body=JSON.parse(init.body);requests.push(body);
  if(body.stream===false) return Response.json({choices:[{message:{content:"Fixed the null check with a regression test."}}]});
  return round++===0 ? call("ask_specialist",{model:"code",task:"Fix the null crash in the supplied handler and explain the test."},"coding") : sse({content:"The handler now checks null."},"stop");
 }});
 assert.equal(requests[0].chat_template_kwargs.enable_thinking,false);
 assert.equal(requests[1].model,"enabled/code");
 assert.match(requests[1].messages[1].content,/null crash/);
 assert.ok(requests[2].messages.some(m=>m.role==="tool"&&m.content.includes("regression test")));
});
