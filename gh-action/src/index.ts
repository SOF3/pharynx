import * as crypto from "crypto"
import * as fsPromises from "fs/promises"
import * as path from "path"
import * as yaml from "js-yaml"
import * as core from "@actions/core"
import * as exec from "@actions/exec"
import * as github from "@actions/github"
import * as http from "@actions/http-client"
import * as io from "@actions/io"
import * as tc from "@actions/tool-cache"
import {PushEvent} from '@octokit/webhooks-definitions/schema'

const composer = core.getBooleanInput("composer")
let pharynxVersion = core.getInput("pharynx-version")
const pluginDir = core.getInput("plugin-dir")
const additionalSources = core.getMultilineInput("additional-sources")
const stagePoggit = core.getBooleanInput("stage-poggit")
const additionalAssets = core.getMultilineInput("additional-assets")

const httpClient = new http.HttpClient("pharynx-action")

;(async () => {
    if(pharynxVersion === "latest") {
        core.info("Detecting latest pharynx version")

        type ReleaseResponse = {
            tag_name: string
        }
        const resp = await httpClient.getJson<ReleaseResponse>("https://github.com/SOF3/pharynx/releases/latest", {
            [http.Headers.Accept]: "application/json",
        })

        if(resp.result === null) {
            return core.setFailed("Failed querying GitHub API for pharynx latest tag")
        }

        pharynxVersion = resp.result.tag_name
    }

    core.info(`Downloading pharynx from https://github.com/SOF3/pharynx/releases/download/${pharynxVersion}/pharynx.phar`)
    const pharynxDownload = await tc.downloadTool(`https://github.com/SOF3/pharynx/releases/download/${pharynxVersion}/pharynx.phar`)
    const pharynxCachePath = await tc.cacheFile(pharynxDownload, "pharynx.phar", "pharynx", pharynxVersion)
    const pharynxPharPath = path.join(pharynxCachePath, "pharynx.phar")

    const outputId = crypto.randomBytes(8).toString("hex")
    const outputDir = path.join("/tmp", outputId)
    const outputPhar = path.join("/tmp", `${outputId}.phar`)

    let args = [
        "-dphar.readonly=0",
        pharynxPharPath,
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

    const pharynxExitCode = await exec.exec("php", args)
    if(pharynxExitCode !== 0) {
        return core.setFailed(`pharynx exited with ${pharynxExitCode}`)
    }

    core.setOutput("output-dir", outputDir)
    core.setOutput("output-phar", outputPhar)

    if(stagePoggit && github.context.eventName) {
        const payload = github.context.payload as PushEvent
        const headCommit = payload.head_commit
        if(headCommit !== null) {
            const srcBranch = github.context.ref.split("/").slice(2).join("/")
            const stageBranch = `poggit/${srcBranch}`

            await exec.exec("git", ["fetch", "origin"])
            const checkoutExitCode = await exec.exec("git", ["checkout", stageBranch], {ignoreReturnCode: true})
            if(checkoutExitCode !== 0) {
                await exec.exec("git", ["checkout", "-b", stageBranch])

                const pluginYmlBuf = await fsPromises.readFile(path.join(outputDir, "plugin.yml"), "utf8")
                const pluginYml: any = await yaml.load(pluginYmlBuf)
                if(typeof pluginYml !== "object" || pluginYml === null || typeof pluginYml.name !== "string") {
                    return core.setFailed("cannot detect plugin name from plugin.yml")
                }
                const pluginName: string = pluginYml.name

                await fsPromises.writeFile(".poggit.yml", JSON.stringify({
                    projects: {
                        [pluginName]: {
                            path: ".",
                            lint: false,
                        },
                    },
                }))
            }

           await exec.exec("git", ["rm", "-r", "--cached", "-f", "."])

            const addArgs = ["add", ".poggit.yml"]
            if(await fsExists("LICENSE")) {
                addArgs.push("LICENSE")
            }
            for(const additionalAsset of additionalAssets) {
                if(await fsExists(additionalAsset)) {
                    addArgs.push(additionalAsset)
                }
            }
            await exec.exec("git", addArgs)

            await exec.exec("git", ["clean", "-dfxf"])

            await io.cp(outputDir, ".", {recursive: true, copySourceDirectory: false})
            await exec.exec("git", ["add", "."])

            await exec.exec("git", [
                "-c", "user.name=github-actions[bot]",
                "-c", "user.email=41898282+github-actions[bot]@users.noreply.github.com",
                "commit",
                "-m",
                `stage(${headCommit.id}): ${headCommit.message}`,
            ])

            await exec.exec("git", ["push", "origin", stageBranch])
        } // else, nothing to build
    }
})()

async function fsExists(file: string): Promise<boolean> {
    try {
        await fsPromises.stat(file)
        return true
    } catch(err) {
        if((err as NodeJS.ErrnoException).code === "ENOENT") {
            return false
        }

        throw err
    }
}
