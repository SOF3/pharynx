import * as crypto from "crypto"
import * as path from "path"
import * as core from "@actions/core"
import * as exec from "@actions/exec"
import * as tc from "@actions/tool-cache"

const composer = core.getInput("composer") === "true"
const composerVersion = core.getInput("composer-version")
const pharynxVersion = core.getInput("pharynx-version")
const pluginDir = core.getInput("plugin-dir")
const additionalSources = core.getInput("additionalSources").split(":").filter(s => s.length > 0)

;(async () => {
	let composerPharPath: string

	if(composer) {
        const download = await tc.downloadTool(`https://github.com/composer/composer/releases/download/${composerVersion}/composer.phar`)
        composerPharPath = await tc.cacheFile(download, "composer.phar", "composer", composerVersion)

        await exec.exec("php", [composerPharPath, "install", "--no-interaction", "--ignore-platform-reqs"])
	}

    const pharynxPath = await tc.downloadTool(`https://github.com/SOF3/pharynx/releases/download/${pharynxVersion}/pharynx.phar`)

    const outputId = crypto.randomBytes(8).toString("hex")
    const outputDir = path.join("/tmp", outputId)
    const outputPhar = path.join("/tmp", `${outputId}.phar`)

    let args = [
        "-dphar.readonly=0",
        pharynxPath,
        "-i", pluginDir,
        "-o", outputDir,
        `-p=${outputPhar}`,
    ]
    if(composer) {
        args.push("-c")
    }
    for(const additionalSource of additionalSources) {
        args.push("-s", additionalSource)
    }

    await exec.exec("php", args)

    core.setOutput("output-dir", outputDir)
    core.setOutput("output-phar", outputPhar)
})()
