import test from 'node:test'
import assert from 'node:assert/strict'
import { documentForEditing } from '../app/utils/editor/document.ts'

function paragraph(text) { return { type: 'paragraph', content: [{ type: 'text', text }] } }

test('old lesson text becomes editable paragraphs with Russian text and calculations intact', () => {
  const first = 'Цена покрытия: 9 × 9 / 2 = 40,5 м². Цена метра — 111 рублей.'
  const second = 'Клиент красит не литры, а стены.'
  assert.deepEqual(documentForEditing(null, `${first}\n\n${second}`), {
    type: 'doc', content: [paragraph(first), paragraph(second)],
  })
})

test('single line breaks survive editing and saving as hard breaks', () => {
  assert.deepEqual(documentForEditing(null, 'Первая строка\nВторая строка'), {
    type: 'doc', content: [{ type: 'paragraph', content: [
      { type: 'text', text: 'Первая строка' }, { type: 'hardBreak' }, { type: 'text', text: 'Вторая строка' },
    ] }],
  })
})

test('Windows line endings and empty separators match the reading page paragraphs', () => {
  assert.deepEqual(documentForEditing(undefined, '  Один\r\n\r\n\r\nДва  '), {
    type: 'doc', content: [paragraph('Один'), paragraph('Два')],
  })
})

test('plain text containing HTML stays literal text instead of becoming HTML', () => {
  assert.deepEqual(documentForEditing(null, '<script>alert(1)</script>'), {
    type: 'doc', content: [paragraph('<script>alert(1)</script>')],
  })
})

test('existing rich content wins without losing video, formatting or attachment ids', () => {
  const document = { type: 'doc', content: [
    { type: 'heading', attrs: { level: 2 }, content: [{ type: 'text', text: 'Заголовок' }] },
    { type: 'videoEmbed', attrs: { attachmentId: 17, provider: 'file' } },
  ] }
  assert.equal(documentForEditing(document, 'Старый текст'), document)
})

test('an intentionally empty block document is never repopulated from stale text', () => {
  const empty = { type: 'doc', content: [{ type: 'paragraph' }] }
  assert.equal(documentForEditing(empty, 'Удалённый текст'), empty)
})

test('a genuinely empty lesson stays empty', () => {
  for (const text of [null, undefined, '', ' \n\n ']) assert.equal(documentForEditing(null, text), null)
})
