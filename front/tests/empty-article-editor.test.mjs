import test from 'node:test'
import assert from 'node:assert/strict'
import { getSchema } from '@tiptap/core'
import StarterKit from '@tiptap/starter-kit'
import { documentForEditor } from '../app/utils/editor/document.ts'
const schema = getSchema([StarterKit])
for (const [name, value] of [['null',null],['empty blocks',{type:'doc',content:[]}],['missing blocks',{type:'doc'}]]) {
  test(`${name} opens as a valid editable paragraph`,()=>{
    const json = documentForEditor(value)
    schema.nodeFromJSON(json).check()
    assert.equal(json.content[0].type,'paragraph')
  })
}
test('existing text is preserved',()=>{
 const doc={type:'doc',content:[{type:'paragraph',content:[{type:'text',text:'Текст урока'}]}]}
 assert.equal(documentForEditor(doc),doc)
 schema.nodeFromJSON(documentForEditor(doc)).check()
})
test('unsupported blocks still fail strict validation rather than being erased',()=>{
 const doc={type:'doc',content:[{type:'unknownBlock'}]}
 assert.equal(documentForEditor(doc),doc)
 assert.throws(()=>schema.nodeFromJSON(documentForEditor(doc)).check())
})
