import test from 'node:test'
import assert from 'node:assert/strict'
import { normalizeDocument, isSameDocument } from '../app/utils/editor/document.ts'

const text = value => ({ type: 'text', text: value })

test('a null text node in a nested list does not discard the article', () => {
  const doc = { type: 'doc', content: [
    { type: 'heading', attrs: { level: 2, blockId: 'stable' }, content: [text('Инструкция')] },
    { type: 'bulletList', content: [{ type: 'listItem', content: [{ type: 'paragraph', content: [text('Пункт'), text(null), text('')] }] }] },
    { type: 'videoEmbed', attrs: { attachmentId: 41, provider: 'file' } },
  ] }
  const clean = normalizeDocument(doc)
  assert.equal(clean.content.length, 3)
  assert.equal(clean.content[0], doc.content[0])
  assert.deepEqual(clean.content[1].content[0].content[0].content, [text('Пункт')])
  assert.equal(clean.content[2], doc.content[2])
  assert.equal(isSameDocument(doc, clean), true)
  assert.equal(doc.content[1].content[0].content[0].content.length, 3)
})

test('spacing between formatting spans and custom HTML must stay unchanged', () => {
  const doc = { type: 'doc', content: [
    { type: 'paragraph', content: [text('Текст '), { ...text('с выделением'), marks: [{ type: 'bold' }] }, text(' '), text('после')] },
    { type: 'htmlBlock', attrs: { html: '<pre>  отступ  </pre>', blockId: 'html' } },
  ] }
  assert.equal(normalizeDocument(doc), doc)
})

test('unknown nodes are preserved so the editor can block saving instead of silently dropping them', () => {
  const doc = { type: 'doc', content: [{ type: 'unsupportedBlock', attrs: { payload: 'valuable content' } }] }
  assert.equal(normalizeDocument(doc), doc)
})

test('empty paragraphs and genuinely empty documents remain editable', () => {
  const empty = { type: 'doc', content: [{ type: 'paragraph' }] }
  assert.equal(normalizeDocument(empty), empty)
  assert.equal(normalizeDocument(null), null)
})
