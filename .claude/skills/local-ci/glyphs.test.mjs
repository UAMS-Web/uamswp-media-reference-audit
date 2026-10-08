import assert from 'node:assert/strict'
import { copyFileSync, existsSync, mkdirSync, mkdtempSync, readFileSync, realpathSync, rmSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { dirname, join } from 'node:path'
import { describe, it } from 'node:test'
import { fileURLToPath, pathToFileURL } from 'node:url'

import { createGlyphScanner, DEFAULT_RULE_PATH, glyphDetail, parseCodePointClass, resolveRulePath, ruleCandidates } from './glyphs.mjs'

const here = dirname(fileURLToPath(import.meta.url))

// The rule's class as code-point ranges, read here independently of glyphs.mjs: a
// line-anchored match on the audit block's assignment, then each `\x{...}` token.
function rangesInRule(ruleText) {
    const lines = ruleText.split(/\r?\n/).filter((line) => line.trimStart().startsWith("GLYPH='"))
    assert.equal(lines.length, 1, 'the rule carries exactly one GLYPH assignment')
    const tokens = [...lines[0].matchAll(/\\x\{([0-9A-Fa-f]+)\}(?:-\\x\{([0-9A-Fa-f]+)\})?/g)]
    assert.ok(tokens.length > 0, 'the assignment carries code points')
    return tokens.map(([, lo, hi]) => ({ lo: Number.parseInt(lo, 16), hi: Number.parseInt(hi ?? lo, 16) }))
}

function ruleAt(text) {
    const dir = mkdtempSync(join(tmpdir(), 'glyphs-rule-'))
    const path = join(dir, 'no-emoji-in-durable-records.md')
    writeFileSync(path, text)
    return { path, cleanup: () => rmSync(dir, { recursive: true, force: true }) }
}

describe('the scanner reads its class from the synced rule', () => {
    it('finds the rule beside the skill, in the source tree and in a target alike', () => {
        assert.ok(existsSync(DEFAULT_RULE_PATH), DEFAULT_RULE_PATH)
        assert.match(DEFAULT_RULE_PATH.split(/[\\/]/).slice(-3).join('/'), /^\.claude\/rules\/no-emoji-in-durable-records\.md$/)
    })

    it('finds the .claude/rules/ copy from both delivered copies of the skill, .claude/ and .agents/', async () => {
        // A target as the sync lays it out: the skill twice, the rule once.
        // Resolved, because a copied module is imported by its real path: where the temp
        // directory is a link (/tmp on macOS) the unresolved spelling never equals it.
        const root = realpathSync(mkdtempSync(join(tmpdir(), 'glyphs-layout-')))
        try {
            const rule = join(root, '.claude', 'rules', 'no-emoji-in-durable-records.md')
            mkdirSync(dirname(rule), { recursive: true })
            writeFileSync(rule, readFileSync(DEFAULT_RULE_PATH))
            for (const copy of ['.claude', '.agents']) {
                const skillDir = join(root, copy, 'skills', 'local-ci')
                mkdirSync(skillDir, { recursive: true })
                copyFileSync(join(here, 'glyphs.mjs'), join(skillDir, 'glyphs.mjs'))
                assert.equal(resolveRulePath(skillDir), rule, copy)

                // The copied module itself, imported from where the sync puts it.
                const copied = await import(pathToFileURL(join(skillDir, 'glyphs.mjs')).href)
                assert.equal(copied.DEFAULT_RULE_PATH, rule, copy)
                assert.ok(copied.createGlyphScanner().ranges.length > 0, copy)
            }
            // With no rule anywhere, the first candidate is named, so the read fails saying where it looked.
            rmSync(rule)
            assert.equal(resolveRulePath(join(root, '.agents', 'skills', 'local-ci')), ruleCandidates(join(root, '.agents', 'skills', 'local-ci'))[0])
        } finally {
            rmSync(root, { recursive: true, force: true })
        }
    })

    it("uses exactly the rule's class: this fails when the two differ", () => {
        assert.deepEqual(createGlyphScanner().ranges, rangesInRule(readFileSync(DEFAULT_RULE_PATH, 'utf8')))
    })

    it('follows a changed rule rather than a copy of its own', () => {
        // U+00A9 is outside the real class; a rule that bans only it changes what is found.
        const rule = ruleAt("GLYPH='[\\x{A9}]'\n")
        try {
            const scan = createGlyphScanner(rule.path)
            assert.deepEqual(scan.ranges, [{ lo: 0xA9, hi: 0xA9 }])
            assert.equal(glyphDetail(scan, `a ${String.fromCodePoint(0xA9)} b`), 'line 1: U+00A9')
            assert.equal(glyphDetail(scan, `a ${String.fromCodePoint(0x26A0)} b`), null)
            assert.notEqual(glyphDetail(createGlyphScanner(), `a ${String.fromCodePoint(0x26A0)} b`), null)
        } finally {
            rule.cleanup()
        }
    })

    it('refuses a rule it cannot read exactly', () => {
        assert.throws(() => parseCodePointClass('[\\x{2600}-\\x{27BF}\\p{Emoji}]'), /unsupported construct/)
        for (const [text, pattern] of [
            ['nothing\n', /found 0/],
            ["GLYPH='[\\x{2600}]'\nGLYPH='[\\x{2700}]'\n", /found 2/],
            ["GLYPH='[\\x{27BF}-\\x{2600}]'\n", /inverted range/],
            ["GLYPH='[\\x{20}-\\x{7E}]'\n", /negative arm/],
        ]) {
            const rule = ruleAt(text)
            try {
                assert.throws(() => createGlyphScanner(rule.path), pattern)
            } finally {
                rule.cleanup()
            }
        }
    })
})

describe('glyphDetail', () => {
    const scan = createGlyphScanner()

    it('names code points by line across the whole text, never the characters', () => {
        const text = `first line\r\nsecond ${String.fromCodePoint(0x2705)}\n\nfourth ${String.fromCodePoint(0x26A0, 0xFE0F)}`
        const detail = glyphDetail(scan, text)
        assert.equal(detail, 'line 2: U+2705; line 4: U+26A0 U+FE0F')
        assert.equal(/[^\x20-\x7E]/.test(detail), false)
    })

    it('is null for clean prose, an empty body and a missing one', () => {
        assert.equal(glyphDetail(scan, 'Plain prose, a -> arrow, and `code`.'), null)
        assert.equal(glyphDetail(scan, ''), null)
        assert.equal(glyphDetail(scan, null), null)
    })
})
